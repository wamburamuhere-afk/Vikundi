# Wise Wallet M-KOBA — demo script

A 20-minute walkthrough that answers the five questions in the prospect's enquiry, in his order.

**Site:** https://wisewallet.vikundi.bjptechnologies.co.tz
**Password:** the one the seeder printed (the same for every account).
**Everything on this site is invented.** Names, numbers and amounts belong to nobody.

| Log in as | Username | Use for |
|---|---|---|
| Chairperson — Amina Mollel | `amollel` | Settings, approvals, expenses |
| Secretary — Daudi Kimaro | `dkimaro` | Members, the prospect's own role |
| Treasurer — Grace Mwakalinga | `gmwakalinga` | M-Koba import, pay-outs, repayments, dividends |
| Member — Charles Mbise | `cmbise` | Has a guarantee request waiting |
| Member — Hassan Mrisho | `hmrisho` | Repaying a loan on time |
| Member — Salim Juma | `sjuma` | Loan overdue, with a penalty |

## 1. "Can it import and reconcile our M-Koba payments?"  *(Treasurer)*
1. Finance → Transactions. Point out that most members have not paid this month yet.
2. Upload M-Koba statement → choose `downloads/WiseWallet_MKoba_Statement_DEMO.csv` (copy it to the laptop first).
3. Result: 23 payments matched to members by phone, TZS 530,000.
4. Finance → M-Koba Reconciliation. Every row is accounted for: 23 imported, a TZS 250,000 group transfer and an account opening excluded, and one TZS 20,000 payment from an unknown number flagged for follow-up.

**Say:** the Treasurer uploads the statement M-Koba already gives you. It is not a live feed.

## 2. "Can members see their own balances online?"  *(Member — `hmrisho`)*
1. Home page: total savings, share capital, shares held, Social Fund paid, membership deposit.
2. Loan balance and the next instalment, with its date.
3. Finance → My Loans → open the loan: the full schedule and what has been paid.

## 3. "Do you support our own loan rules and guarantors?"  *(Chairperson, then members)*
1. Group Settings → Loan Types. Two types with different rates, periods, limits, guarantors and penalties. Edit one live.
2. Log in as `cmbise` → the home page says a member has asked for a guarantee → Review → Accept.
3. Log in as `amollel` → Finance → Loans → the loan is now awaiting approval → Approve.
4. Log in as `gmwakalinga` → open it → Pay out.
5. Still as Treasurer, open Salim Juma's Emergency Loan: one instalment is overdue and shows its penalty. Record a repayment and watch the penalty get paid first.

**Say:** nobody can approve or pay out their own loan, and the group cannot lend more than it holds.

## 4. "Can it calculate dividends on shares and keep the Social Fund separate?"  *(Treasurer)*
1. Finance → Dividends. The surplus is loan interest, penalties and fines, less running costs.
2. The right-hand panel shows share capital, the Social Fund and its welfare payment, all kept out of the dividend.
3. Each member's dividend follows their share of the capital. Choose "added to members' shares" → Confirm → Distribute.
4. Log in as any member: the dividend is on their home page and their shares have grown.

## 5. "What does it cost?"
Not shown on screen. Agreed pricing comes from BJP management.

## Before the demo
- Run through it once end to end, then reset: `php database/seed_wise_wallet_demo.php --fresh --allow-nondemo-db`
- A reset prints a **new** password unless you pass `--password=...`.
- The Expenses page currently opens for the Chairperson only (see the known issue in `sessions.md`).

## Do not promise
- A live, automatic M-Koba feed.
- Loan repayments matched automatically from the M-Koba statement; today the Treasurer records them.
- A mobile app for loans and dividends; they are web-only for now.
