// Payout settings read from the environment (amounts are in the seller's own currency).
export const minWithdrawal = () => {
  const value = Number(process.env["MIN_WITHDRAWAL"]);
  return Number.isFinite(value) && value > 0 ? value : 1000;
};
export const withdrawalFee = () => {
  const value = Number(process.env["WITHDRAWAL_FEE"]);
  return Number.isFinite(value) && value >= 0 ? value : 0;
};
export const MAX_BANK_ACCOUNTS = 3;
