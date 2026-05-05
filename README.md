# Vikundi — VICOBA Group Management System

A comprehensive web-based platform for managing VICOBA (Village Community Bank) savings groups, cooperatives, and informal microfinance institutions in East Africa. Supports English and Swahili (Kiswahili).

## Features

- **Member Management** — Register, approve, and organize members into groups
- **Contributions** — Track member savings and generate statements
- **Loan Management** — Applications, approvals, disbursement, repayment schedules (Flat Rate, Reducing Balance, EMI)
- **Accounting** — Full double-entry bookkeeping: chart of accounts, journals, expenses, budgets, bank reconciliation
- **Documents** — Upload, manage, and digitally sign compliance and loan documents
- **Communications** — Email/SMS templates, notification center, campaigns
- **Reports** — Financial reports, member statements, audit logs, expense analysis
- **Bilingual** — Full English and Swahili (Kiswahili) support

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 |
| Database | MySQL 8.2 |
| Web Server | Apache + mod_rewrite (WAMP) |
| Frontend | Bootstrap 5.3, jQuery 3.7.1, DataTables, SweetAlert2 |
| PDF Export | TCPDF |
| Testing | PHPUnit 11 via Composer |

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Apache with `mod_rewrite` enabled
- Composer

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/wamburamuhere-afk/Vikundi.git
   cd Vikundi
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure the database**
   ```bash
   cp includes/config.example.php includes/config.php
   # Edit includes/config.php with your database credentials
   ```

4. **Import the database schema**
   ```bash
   mysql -u root -p vikundi < database/vikundi.sql
   ```

5. **Configure Apache**
   - Point your virtual host root to the project directory
   - Ensure `mod_rewrite` is enabled
   - Access at `http://localhost/vikundi`

## Running Tests

```bash
# Run all tests
composer test

# Run unit tests only
composer test-unit

# Run feature tests only
composer test-feature

# Generate HTML coverage report
composer test-coverage
```

## Branching Strategy

```
main          ← Production (never commit directly)
  └─ develop  ← Integration branch (all features merge here first)
       └─ feat/feature-name    ← New features
       └─ fix/bug-description  ← Bug fixes
       └─ chore/task-name      ← Maintenance/setup
       └─ hotfix/critical-fix  ← Emergency production fixes
```

All work is done on feature/fix/chore branches.
PR flow: `feature branch` → `develop` → `main` (deployment).

## Contributing

1. Branch from `develop`: `git checkout -b feat/your-feature develop`
2. Make changes with accompanying tests in `tests/`
3. Run `composer test` — all tests must pass
4. Update `sessions.md` with a summary of changes made
5. Open a PR to `develop`

## License

Private — All rights reserved.
