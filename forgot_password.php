<?php
// forgot_password.php
require_once __DIR__ . '/roots.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Vikundi System</title>
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
               
                <p class="text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jaza taarifa zako ili kuthibitisha utambulisho wako' : 'Enter your details to verify your identity' ?></p>
            </div>
            
            <form id="forgotForm" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mtumiaji' : 'Username' ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mtumiaji' : 'Username' ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="nida" class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya NIDA' : 'NIDA Number (ID)' ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                        <input type="text" class="form-control" id="nida" name="nida" placeholder="199XXXXXXXXXXXXXXX" required>
                    </div>
                    <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba tuliyotumia wakati wa usajili' : 'The ID number used during registration' ?></small>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-reset"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'THIBITISHA' : 'VERIFY IDENTITY' ?></button>
                
                <div class="text-center mt-4">
                    <a href="login" class="fw-bold text-decoration-none"><i class="fas fa-arrow-left me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudi Kwenye Login' : 'Back to Login' ?></a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            $('#forgotForm').on('submit', function(e) {
                e.preventDefault();
                $('.btn-reset').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Verifying...');
                $('.btn-reset').prop('disabled', true);
                
                $.ajax({
                    url: 'actions/forgot_password.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Verified!',
                                text: response.message,
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                window.location.href = 'reset_password?token=' + response.token;
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Verification Failed',
                                text: response.message,
                                confirmButtonColor: '#d33'
                            });
                            $('.btn-reset').html('VERIFY IDENTITY');
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
                        $('.btn-reset').html('VERIFY IDENTITY');
                        $('.btn-reset').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>
