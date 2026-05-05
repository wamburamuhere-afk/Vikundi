Scaffold a new feature for the Vikundi VICOBA management system.

Ask the user for:
1. Feature name (e.g., "fine_management", "member_statements")
2. Module: `bms/customer`, `bms/loans`, `constant/accounts`, `constant/reports`, `constant/document`, `constant/communication`, `constant/settings`
3. What to generate: UI page, API endpoint, action handler, AJAX handler (or all)

Then create the following files based on the user's choices:

---

**UI Page** → `/app/<module>/<feature_name>.php`
```php
<?php
require_once '../../includes/config.php';  // adjust depth
require_once '../../core/permissions.php';
requireViewPermission('<page_key>');
$pageTitle = '<Feature Title>';
include '../../header.php';
?>
<!-- Bootstrap 5 layout here -->
<?php include '../../footer.php'; ?>
```

---

**API Endpoint** → `/api/<feature_name>.php`
```php
<?php
require_once '../includes/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');
// ... logic using PDO prepared statements
echo json_encode(['status' => 'success', 'data' => $results]);
```

---

**Action Handler** → `/actions/<feature_name>.php` (POST handler)
```php
<?php
require_once '../includes/config.php';
require_once '../includes/activity_logger.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
// Validate + process
logCreate($_SESSION['user_id'], $_SESSION['full_name'], 'ModuleName', 'Description', $id);
```

---

**Tests** → `tests/Unit/<FeatureName>Test.php` or `tests/Feature/<FeatureName>Test.php`
- Unit: for pure PHP functions in the feature
- Feature: for DB-touching operations

---

**Register route** in `roots.php`:
```php
'<module>/<feature_name>' => BASE_PATH . '/app/<module>/<feature_name>.php',
```

---

After scaffolding, update `sessions.md` with:
- Session number and date
- Branch name
- Files created
- Any DB changes (new tables/columns)
