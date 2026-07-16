<?php
// login.php
require_once __DIR__ . '/roots.php';
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM group_settings");
$gs = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$group_name = $gs['group_name'] ?? 'VIKUNDI MANAGEMENT SYSTEM';
$group_logo = $gs['group_logo'] ?? 'logo1.png';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . (function_exists('getLandingPage') ? getLandingPage() : 'dashboard'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($group_name) ?></title>
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
            padding: 1.8rem;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .logo-wrapper {
            width: 110px;
            height: 110px;
            margin: 0 auto 0.8rem;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            padding: 15px;
        }

        .logo {
            max-width: 75px;
            max-height: 75px;
            height: auto;
            object-fit: contain;
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
        
        .btn-login {
            background-color: var(--primary-color);
            border: none;
            padding: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
        }
        
        .input-group-text {
            background-color: #f1f2f6;
            border-right: none;
        }

        .footer-links a {
            color: #6c757d;
            text-decoration: none;
            font-size: 0.8rem;
            margin: 0 10px;
            transition: color 0.3s;
        }

        .footer-links a:hover {
            color: var(--primary-color) !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="d-flex justify-content-end mb-2 small">
                <a href="?lang=en" class="text-decoration-none me-1 <?= current_lang() === 'en' ? 'fw-bold text-primary' : 'text-muted' ?>"><?= et('common.english') ?></a>
                <span class="text-muted">|</span>
                <a href="?lang=sw" class="text-decoration-none ms-1 <?= current_lang() === 'sw' ? 'fw-bold text-primary' : 'text-muted' ?>"><?= et('common.swahili') ?></a>
            </div>
            <div class="logo-container">
                <div class="logo-wrapper">
                    <img src="assets/images/<?= htmlspecialchars($group_logo) ?>" alt="Logo" class="logo">
                </div>
                <h4 class="fw-bold text-primary mb-3"><?= htmlspecialchars($group_name) ?></h4>
                <p class="text-muted small"><?= et('login.sign_in_prompt') ?></p>
            </div>
            
            <form id="loginForm" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label fw-bold"><?= et('login.username') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="text" class="form-control" id="username" name="username" placeholder="<?= et('login.username_placeholder') ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-bold"><?= et('login.password') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="<?= et('login.password_placeholder') ?>" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label small fw-bold" for="remember">
                            <?= et('login.remember_me') ?>
                        </label>
                    </div>
                    <a href="forgot_password" class="small fw-bold text-decoration-none"><?= et('login.forgot_password') ?></a>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-login"><?= et('login.login_button') ?></button>

                <div class="text-center mt-4 border-top pt-3">
                    <span class="small text-muted"><?= et('login.no_account') ?></span>
                    <a href="register" class="fw-bold text-decoration-none ms-1"><?= et('login.register_here') ?></a>
                </div>

                <div class="footer-links text-center mt-3">
                    <a href="javascript:void(0)" class="js-coming-soon"><?= et('login.privacy_policy') ?></a>
                    <a href="javascript:void(0)" class="js-coming-soon"><?= et('login.terms_of_service') ?></a>
                    <a href="javascript:void(0)" class="js-coming-soon"><?= et('login.help_center') ?></a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const I18N = {
            signingIn:        <?= json_encode(t('login.signing_in')) ?>,
            loginLabel:       <?= json_encode(t('login.login_button')) ?>,
            comingSoon:       <?= json_encode(t('login.coming_soon')) ?>,
            loginFailed:      <?= json_encode(t('login.login_failed')) ?>,
            connectionError:  <?= json_encode(t('login.connection_error')) ?>,
            connectionErrorText: <?= json_encode(t('login.connection_error_text')) ?>
        };

        function comingSoon(title) {
            Swal.fire({
                title: title,
                text: I18N.comingSoon,
                icon: 'info',
                confirmButtonColor: '#007bff'
            });
        }

        $(document).ready(function() {
            $('.js-coming-soon').click(function() {
                comingSoon($(this).text().trim());
            });

            $('#togglePassword').click(function() {
                const password = $('#password');
                const type = password.attr('type') === 'password' ? 'text' : 'password';
                password.attr('type', type);
                $(this).find('i').toggleClass('fa-eye fa-eye-slash');
            });

            $('#loginForm').on('submit', function(e) {
                e.preventDefault();
                $('.btn-login').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + I18N.signingIn);
                $('.btn-login').prop('disabled', true);

                $.ajax({
                    url: 'actions/login.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.redirect || 'dashboard';
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: I18N.loginFailed,
                                text: response.message,
                                confirmButtonColor: '#d33'
                            });
                            $('.btn-login').html(I18N.loginLabel);
                            $('.btn-login').prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: I18N.connectionError,
                            text: I18N.connectionErrorText,
                            confirmButtonColor: '#d33'
                        });
                        $('.btn-login').html(I18N.loginLabel);
                        $('.btn-login').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>