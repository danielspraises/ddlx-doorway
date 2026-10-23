// One-time setup for an existing PostgreSQL server (for example the one you installed on Windows).
// Usage:  npm run db:setup -- "<password of the postgres superuser>" [user] [host] [port]
// It creates a "verola" database and user, then writes DATABASE_URL into .env for you.
import { copyFileSync, existsSync, readFileSync, writeFileSync } from "node:fs";
import postgres from "postgres";

const [password, user = "postgres", host = "localhost", port = "5432"] = process.argv.slice(2);
if (!password) {
  console.error('Usage: npm run db:setup -- "<postgres superuser password>" [user] [host] [port]');
  console.error("This is the password you chose when PostgreSQL was installed (user name: postgres).");
  process.exit(1);
}

const APP_USER = "verola";
const APP_PASSWORD = "verola_dev_password";
const APP_DB = "verola";

const sql = postgres({ host, port: Number(port), user, password, database: "postgres", connect_timeout: 8, onnotice: () => {} });
try {
  const roles = await sql`SELECT 1 FROM pg_roles WHERE rolname = ${APP_USER}`;
  await sql.unsafe(`${roles.length ? "ALTER" : "CREATE"} ROLE ${APP_USER} WITH LOGIN PASSWORD '${APP_PASSWORD}'`);
  const dbs = await sql`SELECT 1 FROM pg_database WHERE datname = ${APP_DB}`;
  if (!dbs.length) await sql.unsafe(`CREATE DATABASE ${APP_DB} OWNER ${APP_USER}`);
  console.log(`OK   Database "${APP_DB}" and user "${APP_USER}" are ready on ${host}:${port}.`);

  const url = `postgres://${APP_USER}:${APP_PASSWORD}@${host}:${port}/${APP_DB}`;
  if (!existsSync(".env") && existsSync(".env.example")) copyFileSync(".env.example", ".env");
  let text = existsSync(".env") ? readFileSync(".env", "utf8") : "";
  text = /^DATABASE_URL=.*$/m.test(text) ? text.replace(/^DATABASE_URL=.*$/m, () => `DATABASE_URL=${url}`) : `DATABASE_URL=${url}\n${text}`;
  writeFileSync(".env", text);
  console.log("OK   .env now points at the new database.");
  console.log("");
  console.log("Next:  npm run db:migrate");
  console.log('       npm run db:admin -- admin@ddlxapparel.com "YourStrongPassword1!"');
  console.log("       npm run dev");
} catch (error) {
  const code = error?.code;
  if (code === "28P01") console.error(`FAIL The password for the "${user}" user is wrong. Try again with the password chosen when PostgreSQL was installed.`);
  else if (code === "ECONNREFUSED") console.error(`FAIL Nothing is listening on ${host}:${port}. Start the PostgreSQL service (Windows: Services -> postgresql-x64-xx -> Start).`);
  else console.error(`FAIL ${code ?? ""} ${error?.message ?? error}`);
  process.exitCode = 1;
} finally {
  await sql.end({ timeout: 1 });
}
