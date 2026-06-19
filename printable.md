# Vikundi — Printable Pages Inventory

All pages in the system that have print functionality, grouped by print method.
Legend: ✅ = shared footer done | ⏭ = skipped (disabled/no route) | 🔒 = skipped (loans — excluded by user)

---

## window.print() Pages

| # | File | Status |
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
| 10 | `app/constant/accounts/petty_cash.php` | ✅ Done |
| 11 | `app/constant/accounts/expenses.php` | ✅ Done |
| 12 | `app/constant/accounts/death_expenses.php` | ✅ Done |
| 13 | `app/constant/accounts/general_expenses.php` | ✅ Done |
| 14 | `app/constant/accounts/budget.php` | ✅ Done |
| 15 | `app/constant/accounts/budget_details.php` | ✅ Done |
| 16 | `app/constant/accounts/transaction_details.php` | ✅ Done |
| 17 | `app/constant/accounts/expense_details.php` | ✅ Done |
| 18 | `app/constant/accounts/journal_details.php` | ✅ Done |
| 19 | `app/constant/accounts/trial_balance.php` | ✅ Done |
| 20 | `app/constant/accounts/reconciliation_details.php` | ✅ Done |
| 21 | `app/constant/accounts/bank_reconciliation.php` | ✅ Done (+ print button added) |
| 22 | `app/bms/loans/loan_details.php` | 🔒 Loans — skipped |
| 23 | `app/bms/pos/attendance.php` | ⏭ Disabled (no route) |
| 24 | `app/bms/sales/sales_order_create.php` | ⏭ Disabled (no route) |
| 25 | `app/bms/grn/grn_create.php` | ⏭ Disabled (no route) |
| 26 | `app/bms/invoice/income_statement.php` | ✅ Done |
| 27 | `app/constant/profile/profile.php` | ✅ Done (removed custom @page + inline footer) |
| 28 | `app/audit_logs.php` | ✅ Done |

---

## DataTables Print Button Pages

| # | File | Status |
|---|---|---|
| 1 | `app/bms/customer/dormant_members.php` | ✅ Done (customize injected) |
| 2 | `app/bms/customer/manage_contributions.php` | ✅ Done (customize injected) |
| 3 | `app/bms/customer/member_approvals.php` | ✅ Done (customize added) |
| 4 | `app/bms/loans/loans_list.php` | 🔒 Loans — skipped |
| 5 | `app/constant/accounts/transactions.php` | ✅ Done (print button added + customize) |
| 6 | `app/constant/accounts/chart_of_accounts.php` | ✅ Done (window.print + @media print) |
| 7 | `app/bms/pos/leaves.php` | ⏭ Disabled (no route) |
| 8 | `app/bms/pos/employees.php` | ⏭ Disabled (no route) |
| 9 | `app/bms/pos/payroll.php` | ⏭ Disabled (no route) |
| 10 | `app/constant/settings/users.php` | ✅ Done (print button added + @media print) |

---

## Summary

| Category | Count |
|---|---|
| Total printable files | 38 |
| ✅ Completed | 24 |
| 🔒 Skipped (loans) | 2 |
| ⏭ Skipped (disabled) | 5 |
| Skipped (already done before this sprint) | 7 |

---

## Shared Footer Files

| File | Purpose |
|---|---|
| `includes/print_footer_css.php` | Canonical CSS — @page margins (10mm 8mm 16mm 8mm), .print-footer styles |
| `includes/print_footer_html.php` | Footer HTML — bilingual (EN/SW), prints name/role/datetime |

---

## Implementation Rules

### window.print() pages
Add at the bottom of the file (before footer.php):
```php
<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
```
Remove any inline footer HTML/CSS that already exists in the file.
Remove any per-file `@page` rule — the shared CSS owns it.

### DataTables print button pages
In the `customize: function(win)` block, inject CSS and HTML that mirrors the shared files exactly:
- `@page { margin: 10mm 8mm 16mm 8mm; }`
- `.print-footer { position:fixed; bottom:0; left:0; right:0; height:16px; ... }`
- `.print-footer p { font-size:7px; color:#2c3e50; line-height:1; margin:0; }`
- `.print-footer .brand { font-size:7px; color:#3498db; font-weight:600; }`
- Footer HTML: `<p>PHRASE <strong>NAME</strong> &mdash; <strong>ROLE</strong> on DATE at TIME</p>`
- Only NAME and ROLE are bold. Date+time is one plain combined string.
