# Vikundi — Printable Pages Inventory

All pages in the system that have print functionality, grouped by print method.
Legend: ✅ = shared footer done | ❌ = still needs shared footer

---

## window.print() Pages — 28 total

| # | File | Shared Footer? |
|---|---|---|
| 1 | `app/bms/customer/customers.php` | ✅ Done |
| 2 | `app/bms/customer/customer_details.php` | ✅ Done |
| 3 | `app/bms/customer/financial_ledger.php` | ✅ Done |
| 4 | `app/constant/reports/member_statement.php` | ✅ Done |
| 5 | `app/constant/reports/vicoba_reports.php` | ✅ Done |
| 6 | `app/constant/reports/death_analysis.php` | ✅ Done |
| 7 | `app/constant/reports/expense_report.php` | ✅ Done |
| 8 | `app/constant/reports/customer_analysis.php` | ✅ Done |
| 9 | `app/constant/accounts/print_petty_cash.php` | ✅ Done |
| 10 | `app/constant/accounts/petty_cash.php` | ❌ Inline |
| 11 | `app/constant/accounts/expenses.php` | ❌ Inline |
| 12 | `app/constant/accounts/death_expenses.php` | ❌ Inline |
| 13 | `app/constant/accounts/general_expenses.php` | ❌ Inline |
| 14 | `app/constant/accounts/budget.php` | ❌ Inline |
| 15 | `app/constant/accounts/budget_details.php` | ❌ Inline |
| 16 | `app/constant/accounts/transaction_details.php` | ❌ Inline |
| 17 | `app/constant/accounts/expense_details.php` | ❌ Inline |
| 18 | `app/constant/accounts/journal_details.php` | ❌ Inline |
| 19 | `app/constant/accounts/trial_balance.php` | ❌ Inline |
| 20 | `app/constant/accounts/reconciliation_details.php` | ❌ Inline |
| 21 | `app/constant/accounts/bank_reconciliation.php` | ❌ Inline |
| 22 | `app/bms/loans/loan_details.php` | ❌ Inline |
| 23 | `app/bms/pos/attendance.php` | ❌ Inline |
| 24 | `app/bms/sales/sales_order_create.php` | ❌ Inline |
| 25 | `app/bms/grn/grn_create.php` | ❌ Inline |
| 26 | `app/bms/invoice/income_statement.php` | ❌ Inline |
| 27 | `app/constant/profile/profile.php` | ❌ Inline |
| 28 | `app/audit_logs.php` | ❌ Inline |

---

## DataTables Print Button Pages — 10 total

| # | File | Shared Footer? |
|---|---|---|
| 1 | `app/bms/customer/dormant_members.php` | ✅ Done (injected) |
| 2 | `app/bms/customer/manage_contributions.php` | ✅ Done (injected) |
| 3 | `app/bms/customer/member_approvals.php` | ❌ Needs work |
| 4 | `app/bms/loans/loans_list.php` | ❌ Inline |
| 5 | `app/constant/accounts/transactions.php` | ❌ Needs work |
| 6 | `app/constant/accounts/chart_of_accounts.php` | ❌ Needs work |
| 7 | `app/bms/pos/leaves.php` | ❌ Needs work |
| 8 | `app/bms/pos/employees.php` | ❌ Needs work |
| 9 | `app/bms/pos/payroll.php` | ❌ Needs work |
| 10 | `app/constant/settings/users.php` | ❌ Needs work |

---

## Totals

| Category | Count |
|---|---|
| Total printable files | 38 |
| Already done ✅ | 11 |
| Still pending ❌ | 27 |
| Uses window.print() | 28 |
| Uses DataTables print button | 10 |

---

## Shared Footer Files

| File | Purpose |
|---|---|
| `includes/print_footer_css.php` | Canonical CSS — @page margins (10mm 8mm 16mm 8mm), .print-footer styles |
| `includes/print_footer_html.php` | Footer HTML — bilingual (EN/SW), prints name/role/datetime |
| `includes/print_footer.php` | Legacy file — DO NOT USE in new implementations |

---

## Implementation Rules

### window.print() pages
Add at the bottom of the file (before footer.php):
```php
<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
```
Remove any inline footer HTML/CSS that already exists in the file.

### DataTables print button pages
In the `customize: function(win)` block, inject CSS and HTML that mirrors the shared files exactly:
- `@page { margin: 10mm 8mm 16mm 8mm; }`
- `.print-footer { position:fixed; bottom:0; left:0; right:0; height:16px; ... }`
- `.print-footer p { font-size:7px; color:#2c3e50; line-height:1; margin:0; }`
- `.print-footer .brand { font-size:7px; color:#3498db; font-weight:600; }`
- Footer HTML: `<p>PHRASE <strong>NAME</strong> &mdash; <strong>ROLE</strong> on DATE at TIME</p>`
- Only NAME and ROLE are bold. Date+time is one plain combined string.
