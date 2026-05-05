<?php
// reset_password.php
require_once __DIR__ . '/roots.php';

$token = $_GET['token'] ?? '';
$session_token = $_SESSION['reset_token'] ?? '';
$reset_time = $_SESSION['reset_time'] ?? 0;

// Simple token validation (within 1 hour)
if (empty($token) || $token !== $session_token || (time() - $reset_time) > 3600) {
    header('Location: login?error=Invalid session or token expired.');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Vikundi System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        :root {
            --primary-color: #007bff;
            --secondary-color: #0056b3;
            --dark-text: #2c3e50;
            --light-bg: #f4f7f6;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .login-container {
            max-width: 450px;
            margin: auto;
            padding: 2.5rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 1rem;
        }
        
        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #dcdde1;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.1);
        }
        
        .btn-reset {
            background-color: var(--primary-color);
            border: none;
            padding: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .btn-reset:hover {
            background-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
        }
        
        .input-group-text {
            background-color: #f1f2f6;
            border-right: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="logo-container">
                <img src="assets/images/logo1.png" alt="Logo" class="logo">
                <h4 class="fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la Siri Jipya' : 'Set New Password' ?></h4>
                <p class="text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jaza neno la siri jipya hapa chini' : 'Enter your new password below' ?></p>
            </div>
            
            <form id="resetForm" method="POST">
                <div class="mb-3">
                    <label for="password" class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la Siri Mapya' : 'New Password' ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="********" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Thibitisha Neno la Siri' : 'Confirm Password' ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-check-circle"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="********" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-reset"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'HIFADHI NENO LA SIRI' : 'RESET PASSWORD' ?></button>
                
                <div class="text-center mt-4">
                    <a href="login" class="fw-bold text-decoration-none"><i class="fas fa-arrow-left me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudi Kwenye Login' : 'Back to Login' ?></a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            $('#resetForm').on('submit', function(e) {
                e.preventDefault();
                const pass = $('#password').val();
                const confirm = $('#confirm_password').val();

                if (pass !== confirm) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Passwords do not match. Please try again.',
                        confirmButtonColor: '#d33'
                    });
                    return;
                }

                $('.btn-reset').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');
                $('.btn-reset').prop('disabled', true);
                
                $.ajax({
                    url: 'actions/reset_password.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Password Reset!',
                                text: response.message,
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                window.location.href = 'login';
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Reset Failed',
                                text: response.message,
                                confirmButtonColor: '#d33'
                            });
                            $('.btn-reset').html('RESET PASSWORD');
                            $('.btn-reset').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while connecting to the server.',
                            confirmButtonColor: '#d33'
                        });
                        $('.btn-reset').html('RESET PASSWORD');
                        $('.btn-reset').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>
