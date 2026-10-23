// Usage:
//   npm run db:admin -- <email> <password>      create the account (or reset it) and make it an admin
//   npm run db:password -- <email> <password>   set a new password for an existing account
import { randomBytes, scrypt } from "node:crypto";
import postgres from "postgres";
import { databaseUrl } from "./_env.mjs";

const [command, email, password] = process.argv.slice(2);
if (!["admin", "password"].includes(command ?? "") || !email || !password) {
  console.error("Usage: node scripts/users.mjs <admin|password> <email> <password>");
  process.exit(1);
}
if (password.length < 8) {
  console.error("The password must be at least 8 characters.");
  process.exit(1);
}

// Same format as src/server/password.server.ts: scrypt$N$r$p$salt$hash
function hashPassword(value) {
  const N = 16384, r = 8, p = 1, salt = randomBytes(16);
  return new Promise((resolve, reject) => {
    scrypt(value, salt, 64, { N, r, p, maxmem: 128 * N * r * 2 }, (error, key) => {
      if (error) reject(error);
      else resolve(`scrypt$${N}$${r}$${p}$${salt.toString("base64")}$${key.toString("base64")}`);
    });
  });
}

const sql = postgres(databaseUrl(), { onnotice: () => {} });
try {
  const hash = await hashPassword(password);
  const address = email.trim().toLowerCase();
  let user = (await sql`SELECT id FROM users WHERE lower(email) = ${address}`)[0];
  if (user) {
    await sql`UPDATE users SET password_hash = ${hash}, email_confirmed_at = coalesce(email_confirmed_at, now()) WHERE id = ${user.id}`;
    await sql`DELETE FROM sessions WHERE user_id = ${user.id}`;
    console.log(`Password updated for ${address}.`);
  } else if (command === "admin") {
    user = (await sql`INSERT INTO users (email, password_hash, email_confirmed_at) VALUES (${address}, ${hash}, now()) RETURNING id`)[0];
    console.log(`Created ${address}.`);
  } else {
    console.error(`No account found for ${address}.`);
    process.exitCode = 1;
  }
  if (user && command === "admin") {
    await sql`INSERT INTO user_roles (user_id, role) VALUES (${user.id}, 'admin') ON CONFLICT (user_id, role) DO NOTHING`;
    console.log(`${address} is an admin.`);
  }
} catch (error) {
  console.error("Failed:", error instanceof Error ? error.message : error);
  process.exitCode = 1;
} finally {
  await sql.end();
}
