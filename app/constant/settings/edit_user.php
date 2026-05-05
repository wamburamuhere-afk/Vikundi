<?php
// Start output buffering at the very beginning
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';

// Check admin permissions
if (!canEdit('users')) {
    // Clear buffer before redirect
    ob_end_clean();
    header("Location: unauthorized.php");
    exit();
}

// Fetch roles from database
$roles = [];
try {
    $stmt = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_name");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors['database'] = 'Error fetching roles: ' . $e->getMessage();
}

// Initialize variables
$errors = [];
$success = false;
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Check if user exists
$user = null;
if ($user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT u.*, r.role_name 
                              FROM users u 
                              LEFT JOIN roles r ON u.role_id = r.role_id 
                              WHERE u.user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errors['database'] = 'Error fetching user: ' . $e->getMessage();
    }
}

if (!$user) {
    // Clear buffer before redirect
    ob_end_clean();
    header("Location: users.php");
    exit();
}

// Set initial form values
$username = $user['username'];
$email = $user['email'];
$first_name = $user['first_name'];
$last_name = $user['last_name'];
$role_id = $user['role_id'];
$current_role_name = $user['role_name'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $role_id = $_POST['role_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate inputs
    if (empty($username)) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($username) < 4) {
        $errors['username'] = 'Username must be at least 4 characters';
    } else {
        // Check if username exists (excluding current user)
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetch()) {
            $errors['username'] = 'Username already exists';
        }
    }

    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email exists (excluding current user)
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (empty($first_name)) {
        $errors['first_name'] = 'First name is required';
    }

    if (empty($last_name)) {
        $errors['last_name'] = 'Last name is required';
    }

    // Validate role against database values
    $valid_role_ids = array_column($roles, 'role_id');
    if (empty($role_id) || !in_array($role_id, $valid_role_ids)) {
        $errors['role_id'] = 'Invalid role selected';
    }

    // Only validate password if provided
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $confirm_password) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            // Update user with or without password change
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users 
                    SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, password = ?
                    WHERE user_id = ?");
                $result = $stmt->execute([$username, $email, $first_name, $last_name, $role_id, $password_hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users 
                    SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?
                    WHERE user_id = ?");
                $result = $stmt->execute([$username, $email, $first_name, $last_name, $role_id, $user_id]);
            }
            
            if ($result) {
                $success = true;
                $_SESSION['success_message'] = 'User updated successfully!';
                // Clear buffer before redirect
                ob_end_clean();
                header("Location: users.php");
                exit();
            } else {
                $errors['database'] = 'Error updating user. Please try again.';
            }
        } catch (PDOException $e) {
            $errors['database'] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Admin Panel</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/font/fonts/bootstrap-icons.css">
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        .breadcrumb {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px 15px;
        }
        .form-label {
            font-weight: 500;
        }
        .password-toggle {
            cursor: pointer;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit User</li>
                    </ol>
                </nav>
                <h2><i class="bi bi-person-gear"></i> Edit User: <?php echo htmlspecialchars($user['username']); ?></h2>
                <p class="text-muted">Edit user information and permissions</p>
            </div>
        </div>

        <?php if (!empty($errors['database'])): ?>
            <div class="alert alert-danger"><?= $errors['database'] ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" 
                                   id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>
                            <?php if (isset($errors['username'])): ?>
                                <div class="invalid-feedback"><?= $errors['username'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                   id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?= $errors['email'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First Name *</label>
                            <input type="text" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                   id="first_name" name="first_name" value="<?= htmlspecialchars($first_name) ?>" required>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="invalid-feedback"><?= $errors['first_name'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last Name *</label>
                            <input type="text" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" 
                                   id="last_name" name="last_name" value="<?= htmlspecialchars($last_name) ?>" required>
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="invalid-feedback"><?= $errors['last_name'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="role_id" class="form-label">Role *</label>
                            <select class="form-select <?= isset($errors['role_id']) ? 'is-invalid' : '' ?>" 
                                    id="role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['role_id'] ?>" <?= $role_id == $role['role_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($role['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['role_id'])): ?>
                                <div class="invalid-feedback"><?= $errors['role_id'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                       id="password" name="password">
                                <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback d-block"><?= $errors['password'] ?></div>
                            <?php else: ?>
                                <small class="text-muted">Minimum 8 characters</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                       id="confirm_password" name="confirm_password">
                                <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <div class="invalid-feedback d-block"><?= $errors['confirm_password'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-check-circle"></i> Update User
                            </button>
                            <a href="users.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Password visibility toggle
    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = field.parentNode.querySelector('i');
        
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        
        form.addEventListener('submit', function(event) {
            let valid = true;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Only validate passwords if they are provided
            if (password !== '' || confirmPassword !== '') {
                if (password.length < 8) {
                    valid = false;
                    alert('Password must be at least 8 characters long');
                } else if (password !== confirmPassword) {
                    valid = false;
                    alert('Passwords do not match');
                }
            }
            
            if (!valid) {
                event.preventDefault();
            }
        });
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php 
// Close database connection
$pdo = null;

// Flush the output buffer
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>