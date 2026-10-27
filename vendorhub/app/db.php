<?php
declare(strict_types=1);

// Thin PDO wrapper. SQL stays portable (MySQL / MariaDB in production).
final class Db
{
    public PDO $pdo;
    public string $driver;
    private int $depth = 0;

    public function __construct(array $c)
    {
        $this->driver = $c['driver'] ?? 'mysql';
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . $c['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            return;
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', (int) ($c['port'] ?? 3306), $c['name']);
        $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    }

    public function q(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute(array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($params)));
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->q($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $r = $this->q($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->q($sql, $params)->rowCount();
    }

    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $this->q('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
    }

    // Runs $fn in a transaction. Nested calls join the outer one.
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    // Row locks only exist on MySQL; SQLite (used by the self-tests) serialises writers anyway.
    public function lock(): string
    {
        return $this->driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    public static function isDuplicate(Throwable $e): bool
    {
        return $e instanceof PDOException && ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint') || str_contains($e->getMessage(), 'Duplicate entry'));
    }

    public function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}

function db(): Db
{
    static $db = null;
    if ($db === null) {
        $db = new Db(cfg('db', []));
    }
    return $db;
}
