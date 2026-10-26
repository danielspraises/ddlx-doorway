import { createServerFn } from "@tanstack/react-start";
import { z } from "zod";
import type { Failure, PayoutOverview, WithdrawalReviewItem } from "@/lib/types";

type Result = { ok: true } | Failure;
const fail = (error: string): Failure => ({ ok: false, error });

export const getPayouts = createServerFn({ method: "GET" }).handler(async (): Promise<PayoutOverview> => {
  const { requireVerifiedSeller } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  const { minWithdrawal, withdrawalFee } = await import("@/server/payouts.server");
  const user = await requireVerifiedSeller();
  const sql = getSql();
  const accounts = await sql<PayoutOverview["accounts"]>`
    SELECT id, bank_name, account_number, account_name FROM bank_accounts WHERE seller_id = ${user.id} ORDER BY created_at`;
  const withdrawals = await sql<PayoutOverview["withdrawals"]>`
    SELECT id, amount::float8 AS amount, fee::float8 AS fee, currency, bank_name, account_number, account_name, status, admin_note, reference, requested_at, decided_at
    FROM withdrawals WHERE seller_id = ${user.id} ORDER BY requested_at DESC LIMIT 100`;
  const available = await sql<PayoutOverview["available"]>`
    SELECT currency, COALESCE(sum(amount) FILTER (WHERE available_at <= now()), 0)::float8 AS amount
    FROM wallet_entries WHERE seller_id = ${user.id} GROUP BY currency ORDER BY currency`;
  return { accounts: [...accounts], withdrawals: withdrawals.map((w) => plain(w)), available: [...available], min_withdrawal: minWithdrawal(), fee: withdrawalFee() };
});

export const addBankAccount = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({
    bank_name: z.string().trim().min(2, "Enter the bank name").max(100),
    account_number: z.string().trim().regex(/^[0-9]{10}$/, "The account number must be 10 digits"),
    account_name: z.string().trim().min(2, "Enter the account name").max(120),
  }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql, isUniqueViolation } = await import("@/server/db.server");
    const { MAX_BANK_ACCOUNTS } = await import("@/server/payouts.server");
    const user = await requireVerifiedSeller();
    try {
      return await getSql().begin(async (tx): Promise<Result> => {
        await tx`SELECT pg_advisory_xact_lock(hashtext(${"bank:" + user.id}))`;
        const count = await tx<{ n: number }[]>`SELECT count(*)::int AS n FROM bank_accounts WHERE seller_id = ${user.id}`;
        if ((count[0]?.n ?? 0) >= MAX_BANK_ACCOUNTS) return fail(`You can save up to ${MAX_BANK_ACCOUNTS} bank accounts. Remove one first.`);
        await tx`INSERT INTO bank_accounts (seller_id, bank_name, account_number, account_name) VALUES (${user.id}, ${data.bank_name}, ${data.account_number}, ${data.account_name})`;
        return { ok: true };
      });
    } catch (error) {
      if (isUniqueViolation(error)) return fail("This account is already saved.");
      throw error;
    }
  });

export const removeBankAccount = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const user = await requireVerifiedSeller();
    await getSql()`DELETE FROM bank_accounts WHERE id = ${data.id} AND seller_id = ${user.id}`;
    return { ok: true };
  });

// Takes the money out of the wallet straight away so it cannot be spent twice.
export const requestWithdrawal = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({
    bank_account_id: z.string().uuid(),
    currency: z.enum(["NGN", "USD", "GBP", "EUR"]),
    amount: z.number().positive().max(1_000_000_000),
  }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const { minWithdrawal, withdrawalFee } = await import("@/server/payouts.server");
    const { announce } = await import("@/server/events.server");
    const user = await requireVerifiedSeller();
    const cents = Math.round(data.amount * 100);
    const amount = cents / 100;
    const fee = withdrawalFee();
    if (amount < minWithdrawal()) return fail(`The smallest withdrawal is ${minWithdrawal().toLocaleString()}.`);
    if (fee >= amount) return fail("The amount must be more than the withdrawal fee.");
    const result = await getSql().begin(async (tx): Promise<Result> => {
      // One request at a time per seller, so two clicks cannot both pass the balance check.
      await tx`SELECT pg_advisory_xact_lock(hashtext(${"wallet:" + user.id}))`;
      const bank = await tx<{ bank_name: string; account_number: string; account_name: string }[]>`
        SELECT bank_name, account_number, account_name FROM bank_accounts WHERE id = ${data.bank_account_id} AND seller_id = ${user.id}`;
      const account = bank[0];
      if (!account) return fail("Choose one of your saved bank accounts.");
      const bal = await tx<{ available: number }[]>`
        SELECT COALESCE(sum(amount) FILTER (WHERE available_at <= now()), 0)::float8 AS available
        FROM wallet_entries WHERE seller_id = ${user.id} AND currency = ${data.currency}`;
      if (Math.round((bal[0]?.available ?? 0) * 100) < cents) return fail("That is more than your available balance.");
      const open = await tx<{ n: number }[]>`SELECT count(*)::int AS n FROM withdrawals WHERE seller_id = ${user.id} AND status = 'requested'`;
      if ((open[0]?.n ?? 0) >= 5) return fail("You have several requests waiting. Wait for them to be paid first.");
      const row = await tx<{ id: string }[]>`
        INSERT INTO withdrawals (seller_id, amount, fee, currency, bank_name, account_number, account_name)
        VALUES (${user.id}, ${amount}, ${fee}, ${data.currency}, ${account.bank_name}, ${account.account_number}, ${account.account_name}) RETURNING id`;
      await tx`
        INSERT INTO wallet_entries (seller_id, kind, amount, currency, note, withdrawal_id)
        VALUES (${user.id}, 'withdrawal', ${-amount}, ${data.currency}, 'Withdrawal request', ${row[0]?.id ?? null})`;
      return { ok: true };
    });
    if (result.ok) await announce({ audience: "admins", type: "payout", message: "A withdrawal is waiting to be paid" });
    return result;
  });

// Puts a request's money back into the wallet (cancel or reject). Safe to call once per withdrawal.
async function giveBack(tx: Parameters<Parameters<ReturnType<typeof import("@/server/db.server").getSql>["begin"]>[0]>[0], id: string, note: string) {
  await tx`
    INSERT INTO wallet_entries (seller_id, kind, amount, currency, note, withdrawal_id)
    SELECT seller_id, 'adjustment', amount, currency, ${note}, id FROM withdrawals WHERE id = ${id}
    ON CONFLICT DO NOTHING`;
}

export const cancelWithdrawal = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({ id: z.string().uuid() }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireVerifiedSeller } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const user = await requireVerifiedSeller();
    return getSql().begin(async (tx): Promise<Result> => {
      const rows = await tx<{ id: string }[]>`
        UPDATE withdrawals SET status = 'cancelled', decided_at = now()
        WHERE id = ${data.id} AND seller_id = ${user.id} AND status = 'requested' RETURNING id`;
      if (!rows[0]) return fail("This request can no longer be cancelled.");
      await giveBack(tx, data.id, "Withdrawal cancelled");
      return { ok: true };
    });
  });

export const listWithdrawalsForReview = createServerFn({ method: "GET" }).handler(async (): Promise<WithdrawalReviewItem[]> => {
  const { requireAdmin } = await import("@/server/session.server");
  const { getSql, plain } = await import("@/server/db.server");
  await requireAdmin();
  const rows = await getSql()<WithdrawalReviewItem[]>`
    SELECT w.id, w.amount::float8 AS amount, w.fee::float8 AS fee, w.currency, w.bank_name, w.account_number, w.account_name,
      w.status, w.admin_note, w.reference, w.requested_at, w.decided_at,
      COALESCE(NULLIF(trim(a.brand ->> 'store_name'), ''), u.email) AS store_name, u.email AS seller_email
    FROM withdrawals w JOIN users u ON u.id = w.seller_id LEFT JOIN seller_applications a ON a.user_id = w.seller_id
    ORDER BY (w.status = 'requested') DESC, w.requested_at DESC LIMIT 200`;
  return rows.map((row) => plain(row));
});

export const decideWithdrawal = createServerFn({ method: "POST" })
  .inputValidator((input: unknown) => z.object({
    id: z.string().uuid(),
    decision: z.enum(["paid", "rejected"]),
    reference: z.string().trim().max(120).optional(),
    note: z.string().trim().max(500).optional(),
  }).parse(input))
  .handler(async ({ data }): Promise<Result> => {
    const { requireAdmin } = await import("@/server/session.server");
    const { getSql } = await import("@/server/db.server");
    const { announce } = await import("@/server/events.server");
    const admin = await requireAdmin();
    if (data.decision === "paid" && !data.reference) return fail("Enter the transfer reference you paid with.");
    if (data.decision === "rejected" && !data.note) return fail("Tell the seller why it was rejected.");
    const outcome = await getSql().begin(async (tx): Promise<{ result: Result; seller?: string; amount?: number }> => {
      const rows = await tx<{ seller_id: string; amount: number }[]>`
        UPDATE withdrawals SET status = ${data.decision}, reference = ${data.reference ?? null}, admin_note = ${data.note ?? null},
          decided_by = ${admin.id}, decided_at = now()
        WHERE id = ${data.id} AND status = 'requested' RETURNING seller_id, amount::float8 AS amount`;
      const row = rows[0];
      if (!row) return { result: fail("This request has already been decided.") };
      if (data.decision === "rejected") await giveBack(tx, data.id, "Withdrawal rejected");
      return { result: { ok: true }, seller: row.seller_id, amount: row.amount };
    });
    if (outcome.result.ok && outcome.seller) {
      await announce({ audience: "seller", seller_id: outcome.seller, type: "payout", message: data.decision === "paid" ? "Your withdrawal has been paid" : "Your withdrawal was rejected and refunded to your wallet" });
    }
    return outcome.result;
  });
