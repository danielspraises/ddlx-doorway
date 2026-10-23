// Usage: npm run db:check  - explains what is wrong with the database setup, in plain words.
import postgres from "postgres";
import { databaseUrl } from "./_env.mjs";

const url = databaseUrl();
const shown = url.replace(/:\/\/([^:]+):[^@]*@/, "://$1:****@");
console.log(`Using DATABASE_URL = ${shown}`);
const sql = postgres(url, { onnotice: () => {}, connect_timeout: 5 });
try {
  await sql`SELECT 1`;
  console.log("OK   Connected to the database.");
} catch (error) {
  console.log(`FAIL Could not connect: ${error?.code ?? ""} ${error?.message ?? error}`);
  console.log("     Is the database running? With Docker: docker compose up -d. Check the address/password in .env.");
  await sql.end({ timeout: 1 });
  process.exit(1);
}
try {
  const tables = (await sql`SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'`).map((row) => row.table_name);
  const missing = ["users", "sessions", "user_roles", "seller_applications", "seller_verifications"].filter((name) => !tables.includes(name));
  if (missing.length) {
    console.log(`FAIL Missing tables: ${missing.join(", ")}. Run: npm run db:migrate`);
    process.exitCode = 1;
  } else {
    console.log("OK   All tables exist.");
    const [{ count: users }] = await sql`SELECT count(*)::int AS count FROM users`;
    const admins = await sql`SELECT u.email FROM user_roles r JOIN users u ON u.id = r.user_id WHERE r.role = 'admin'`;
    console.log(`INFO ${users} user(s) in the database.`);
    if (admins.length) console.log(`OK   Admin account(s): ${admins.map((row) => row.email).join(", ")}`);
    else console.log('FAIL No admin exists yet. Run: npm run db:admin -- admin@ddlxapparel.com "YourStrongPassword1!"');
  }
} catch (error) {
  console.log(`FAIL ${error?.message ?? error}`);
  process.exitCode = 1;
} finally {
  await sql.end({ timeout: 1 });
}
