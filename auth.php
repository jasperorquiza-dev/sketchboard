<?php
// auth.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = sketch_base_url();
$assetBase = sketch_asset_base();

// If already logged in, redirect to dashboard (index.php)
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $baseUrl . '/');
    exit;
}

$error = '';
$success = '';
$activeTab = 'login'; // 'login', 'register', or 'verify'

function sendSmtpEmail($to, $subject, $htmlContent) {
    $host = (string) sketch_config('smtp.host', '');
    $port = (int) sketch_config('smtp.port', 587);
    $username = (string) sketch_config('smtp.username', '');
    $password = (string) sketch_config('smtp.password', '');
    $fromEmail = (string) sketch_config('smtp.from_email', $username);
    $fromName = (string) sketch_config('smtp.from_name', 'Sketchboard');

    if ($host === '' || $username === '' || $password === '') {
        throw new Exception('SMTP is not configured. Update config.php before sending email.');
    }

    $socket = fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) {
        throw new Exception("Could not connect to SMTP host: $errstr ($errno)");
    }

    $read = function($socket) {
        $data = '';
        while (($line = fgets($socket, 512)) !== false) {
            $data .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = function($socket, $cmd) {
        fwrite($socket, $cmd . "\r\n");
    };

    $read($socket); // read banner
    
    $write($socket, "EHLO localhost");
    $read($socket);

    $write($socket, "STARTTLS");
    $res = $read($socket);
    if (strpos($res, '220') === false) {
        throw new Exception("STARTTLS failed: " . $res);
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
        throw new Exception("Failed to start encryption");
    }

    $write($socket, "EHLO localhost");
    $read($socket);

    $write($socket, "AUTH LOGIN");
    $read($socket);

    $write($socket, base64_encode($username));
    $read($socket);

    $write($socket, base64_encode($password));
    $res = $read($socket);
    if (strpos($res, '235') === false) {
        throw new Exception("SMTP Authentication failed: " . $res);
    }

    $write($socket, "MAIL FROM:<$fromEmail>");
    $read($socket);

    $write($socket, "RCPT TO:<$to>");
    $read($socket);

    $write($socket, "DATA");
    $read($socket);

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: $fromName <$fromEmail>",
        "To: <$to>",
        "Subject: $subject",
        "Date: " . date('r'),
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $htmlContent . "\r\n.";
    $write($socket, $emailData);
    $res = $read($socket);

    $write($socket, "QUIT");
    fclose($socket);

    return true;
}

function getVerificationEmailHtml($username, $code) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Caveat:wght@700&family=Courier+Prime&family=Inter:wght@400;600&display=swap");
            body {
                margin: 0;
                padding: 40px 20px;
                background-color: #f5ebe0;
                font-family: "Inter", sans-serif;
                color: #2b2b2b;
            }
            .notebook-outer {
                max-width: 480px;
                margin: 0 auto;
                background-color: #fdfaf2;
                border: 2px solid #4a3e3d;
                border-radius: 8px;
                box-shadow: 5px 5px 0px #4a3e3d;
                overflow: hidden;
            }
            .notebook-header {
                background-color: #ebdcb9;
                border-bottom: 2px solid #4a3e3d;
                padding: 15px 25px;
                text-align: center;
            }
            .notebook-header h2 {
                margin: 0;
                font-family: "Caveat", cursive;
                font-size: 28px;
                color: #4a3e3d;
                letter-spacing: 1px;
            }
            .notebook-paper {
                padding: 30px 25px;
                position: relative;
                background-image: repeating-linear-gradient(#fdfaf2, #fdfaf2 27px, #e6d8c1 27px, #e6d8c1 28px);
                line-height: 28px;
            }
            .notebook-paper::before {
                content: "";
                position: absolute;
                top: 0;
                bottom: 0;
                left: 45px;
                width: 2px;
                background-color: #ff9e9e;
            }
            .notebook-content {
                padding-left: 35px;
                font-family: "Courier Prime", monospace;
                font-size: 15px;
            }
            .code-box {
                display: inline-block;
                margin: 20px 0;
                padding: 10px 20px;
                background-color: #fff9db;
                border: 2px dashed #b5a932;
                font-family: "Courier Prime", monospace;
                font-size: 32px;
                font-weight: bold;
                letter-spacing: 5px;
                color: #111111;
                border-radius: 4px;
                transform: rotate(-1deg);
                box-shadow: 2px 2px 0px rgba(0,0,0,0.05);
            }
            .footer {
                margin-top: 30px;
                font-size: 12px;
                color: #887d72;
                border-top: 1px dashed #e6d8c1;
                padding-top: 15px;
                line-height: 1.4;
            }
        </style>
    </head>
    <body>
        <div class="notebook-outer">
            <div class="notebook-header">
                <h2>Sketchboard Notes</h2>
            </div>
            <div class="notebook-paper">
                <div class="notebook-content">
                    Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',<br><br>
                    Welcome to Sketchboard!<br>
                    Your registration verification code is:<br>
                    <div style="text-align: center; margin-left: -35px; margin-right: 0;">
                        <div class="code-box">' . $code . '</div>
                    </div>
                    Enter this code on the register screen to verify your email.<br>
                    This code will expire in 10 minutes.<br><br>
                    Happy sketching,<br>
                    The Sketchboard Team
                    <div class="footer">
                        Sketchboard No-Reply Provider<br>
                        This is an automated security verification message. Please do not reply.
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    ';
}

function getResetEmailHtml($username, $resetLink) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Caveat:wght@700&family=Courier+Prime&family=Inter:wght@400;600&display=swap");
            body {
                margin: 0;
                padding: 40px 20px;
                background-color: #f5ebe0;
                font-family: "Inter", sans-serif;
                color: #2b2b2b;
            }
            .notebook-outer {
                max-width: 480px;
                margin: 0 auto;
                background-color: #fdfaf2;
                border: 2px solid #4a3e3d;
                border-radius: 8px;
                box-shadow: 5px 5px 0px #4a3e3d;
                overflow: hidden;
            }
            .notebook-header {
                background-color: #ebdcb9;
                border-bottom: 2px solid #4a3e3d;
                padding: 15px 25px;
                text-align: center;
            }
            .notebook-header h2 {
                margin: 0;
                font-family: "Caveat", cursive;
                font-size: 28px;
                color: #4a3e3d;
                letter-spacing: 1px;
            }
            .notebook-paper {
                padding: 30px 25px;
                position: relative;
                background-image: repeating-linear-gradient(#fdfaf2, #fdfaf2 27px, #e6d8c1 27px, #e6d8c1 28px);
                line-height: 28px;
            }
            .notebook-paper::before {
                content: "";
                position: absolute;
                top: 0;
                bottom: 0;
                left: 45px;
                width: 2px;
                background-color: #ff9e9e;
            }
            .notebook-content {
                padding-left: 35px;
                font-family: "Courier Prime", monospace;
                font-size: 15px;
            }
            .reset-btn {
                display: inline-block;
                margin: 20px 0;
                padding: 12px 24px;
                background-color: #fff9db;
                border: 2px dashed #b5a932;
                font-family: "Courier Prime", monospace;
                font-size: 18px;
                font-weight: bold;
                color: #111111 !important;
                text-decoration: none;
                border-radius: 4px;
                transform: rotate(-1deg);
                box-shadow: 2px 2px 0px rgba(0,0,0,0.05);
            }
            .reset-btn:hover {
                background-color: #fffdd0;
            }
            .footer {
                margin-top: 30px;
                font-size: 12px;
                color: #887d72;
                border-top: 1px dashed #e6d8c1;
                padding-top: 15px;
                line-height: 1.4;
            }
        </style>
    </head>
    <body>
        <div class="notebook-outer">
            <div class="notebook-header">
                <h2>Sketchboard Notes</h2>
            </div>
            <div class="notebook-paper">
                <div class="notebook-content">
                    Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',<br><br>
                    You requested to reset your password.<br>
                    Please click the button below to change it:<br>
                    <div style="text-align: center; margin-left: -35px; margin-right: 0;">
                        <a href="' . $resetLink . '" class="reset-btn">RESET PASSWORD</a>
                    </div>
                    This link will expire in 1 hour.<br>
                    If you did not request this, you can ignore this email.<br><br>
                    Happy sketching,<br>
                    The Sketchboard Team
                    <div class="footer">
                        Sketchboard No-Reply Provider<br>
                        This is an automated security verification message. Please do not reply.
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    ';
}

// Check for password reset via GET (from email link)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'reset_password') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token !== '') {
        try {
            $stmt = $db->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > ? LIMIT 1");
            $stmt->execute([$token, time()]);
            $reset = $stmt->fetch();
            if ($reset) {
                $_SESSION['reset_token'] = $token;
                $_SESSION['reset_email'] = $reset['email'];
                $activeTab = 'reset';
                $success = 'Verification successful. Please enter your new password below.';
            } else {
                $error = 'The password reset link is invalid or has expired.';
                $activeTab = 'login';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Verify CSRF Token
    if (!sketch_verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired or invalid security token. Please try again.';
        $activeTab = ($action === 'forgot_request') ? 'forgot' : (($action === 'reset_submit') ? 'reset' : $action);
    } elseif ($action !== '' && sketch_is_rate_limited($action, 5, 60)) {
        $error = 'Too many requests. Please wait a minute and try again.';
        $activeTab = ($action === 'forgot_request') ? 'forgot' : (($action === 'reset_submit') ? 'reset' : $action);
    } else {
        if ($action === 'login') {
            $activeTab = 'login';
            $usernameOrEmail = trim((string)($_POST['username_or_email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($usernameOrEmail === '' || $password === '') {
                $error = 'Please fill in all fields.';
            } else {
                try {
                    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        
                        header('Location: ' . $baseUrl . '/');
                        exit;
                    } else {
                        $error = 'Invalid username/email or password.';
                    }
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'register') {
            $activeTab = 'register';
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($username === '' || $email === '' || $password === '' || $confirmPassword === '') {
                $error = 'Please fill in all fields.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $error = 'Password must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, and one number.';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                $error = 'Username must be 3-20 characters (alphanumeric and underscores).';
            } else {
                try {
                    // Check if username already exists
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error = 'Username is already taken.';
                    } else {
                        // Check if email already exists
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $error = 'Email is already registered.';
                        } else {
                            // Generate code
                            $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                            
                            // Send verification email
                            $emailHtml = getVerificationEmailHtml($username, $code);
                            try {
                                sendSmtpEmail($email, 'Verify your Sketchboard Account', $emailHtml);
                                
                                // Store in session
                                $_SESSION['pending_user'] = [
                                    'username' => $username,
                                    'email' => $email,
                                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                                    'code' => $code,
                                    'expires' => time() + 600
                                ];
                                
                                $success = 'A verification code has been sent to your email. Please enter it below.';
                                $activeTab = 'verify';
                            } catch (Exception $e) {
                                $error = 'Failed to send verification email: ' . $e->getMessage();
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'verify') {
            $activeTab = 'verify';
            $code = trim((string)($_POST['code'] ?? ''));

            if ($code === '') {
                $error = 'Please enter the verification code.';
            } elseif (!isset($_SESSION['pending_user'])) {
                $error = 'No registration session found. Please register again.';
                $activeTab = 'register';
            } elseif (time() > $_SESSION['pending_user']['expires']) {
                $error = 'Verification code has expired. Please register again.';
                unset($_SESSION['pending_user']);
                $activeTab = 'register';
            } elseif ($code !== $_SESSION['pending_user']['code']) {
                $error = 'Invalid verification code.';
            } else {
                try {
                    $pending = $_SESSION['pending_user'];
                    
                    // Double check if username/email was taken in the meantime
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$pending['username'], $pending['email']]);
                    if ($stmt->fetch()) {
                        $error = 'Username or email was taken. Please register again.';
                        unset($_SESSION['pending_user']);
                        $activeTab = 'register';
                    } else {
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$pending['username'], $pending['email'], $pending['password_hash'], time()]);
                        
                        unset($_SESSION['pending_user']);
                        $success = 'Registration successful! You can now log in.';
                        $activeTab = 'login';
                    }
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'forgot_request') {
            $activeTab = 'forgot';
            $email = trim((string)($_POST['email'] ?? ''));

            if ($email === '') {
                $error = 'Please enter your email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                try {
                    // Check if user exists
                    $stmt = $db->prepare("SELECT username FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        $error = 'This email address is not registered.';
                    } else {
                        // Generate token
                        $token = bin2hex(random_bytes(16));
                        $expires = time() + 3600; // 1 hour

                        // Store token in database
                        $stmt = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                        $stmt->execute([$email, $token, $expires]);

                        // Send email
                        $resetLink = $baseUrl . '/auth.php?action=reset_password&token=' . $token;
                        $emailHtml = getResetEmailHtml($user['username'], $resetLink);
                        
                        try {
                            sendSmtpEmail($email, 'Reset your Sketchboard Password', $emailHtml);
                            $success = 'A password reset link has been sent to your email.';
                            $activeTab = 'login';
                        } catch (Exception $e) {
                            $error = 'Failed to send reset email: ' . $e->getMessage();
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'reset_submit') {
            $activeTab = 'reset';
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $token = $_SESSION['reset_token'] ?? '';
            $email = $_SESSION['reset_email'] ?? '';

            if ($token === '' || $email === '') {
                $error = 'Reset session expired. Please request a new link.';
                $activeTab = 'login';
            } elseif ($password === '' || $confirmPassword === '') {
                $error = 'Please fill in all fields.';
            } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $error = 'Password must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, and one number.';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } else {
                try {
                    // Verify token again
                    $stmt = $db->prepare("SELECT id FROM password_resets WHERE token = ? AND email = ? AND expires_at > ? LIMIT 1");
                    $stmt->execute([$token, $email, time()]);
                    if (!$stmt->fetch()) {
                        $error = 'Invalid or expired token.';
                        $activeTab = 'login';
                    } else {
                        // Update password
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                        $stmt->execute([$hash, $email]);

                        // Delete token
                        $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                        $stmt->execute([$email]);

                        unset($_SESSION['reset_token']);
                        unset($_SESSION['reset_email']);

                        $success = 'Password reset successfully! You can now log in.';
                        $activeTab = 'login';
                    }
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Sketchboard - Sign In / Register</title>

    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/fonts/fonts.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/style.css?v=<?php echo (int)filemtime(__DIR__ . '/style.css'); ?>">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/favicon.svg">

    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            background-color: var(--clr-paper);
            background-image: 
                linear-gradient(var(--clr-rule-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--clr-rule-line) 1px, transparent 1px);
            background-size: 24px 24px;
            overflow-y: auto;
            padding: 20px;
        }

        .auth-container {
            background: var(--clr-paper);
            border: var(--border-width) solid var(--border-color);
            box-shadow: var(--shadow-stacked);
            width: min(92vw, 420px);
            padding: clamp(24px, 5vw, 40px);
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 24px;
            position: relative;
            margin: 20px 0;
            transform: rotate(-0.5deg);
            transition: transform 0.2s ease;
            border-radius: 6px;
        }

        .auth-container:hover {
            transform: rotate(0deg);
        }

        .logo-area h1 {
            font-family: var(--font-header);
            font-size: clamp(3.2rem, 10vw, 4.2rem);
            margin: 0;
            color: #8d6e63; /* Elegant warm leather/stamp color */
            font-weight: 700;
            letter-spacing: 2px;
            line-height: 1;
            transform: rotate(-2.5deg);
            display: inline-block;
        }

        .auth-tabs {
            display: flex;
            background: rgba(0,0,0,0.03);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 6px;
            padding: 3px;
            gap: 4px;
        }

        .auth-tab {
            flex: 1;
            padding: 10px;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.85rem;
            background: transparent;
            color: var(--clr-ink-light);
            border: none;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.15s ease;
        }

        .auth-tab.active {
            background: var(--clr-yellow);
            color: var(--clr-ink);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .auth-tab:not(.active):hover {
            background: rgba(0,0,0,0.04);
            color: var(--clr-ink);
        }

        .auth-form {
            display: none;
            flex-direction: column;
            gap: 15px;
            text-align: left;
        }

        .auth-form.active {
            display: flex;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .input-group label {
            font-weight: 700;
            font-size: 0.75rem;
            color: var(--clr-ink-light);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .auth-input {
            padding: 10px 14px;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            font-family: var(--font-body);
            font-size: 0.95rem;
            width: 100%;
            background: #ffffff;
            transition: all 0.15s ease;
        }

        .auth-input:focus {
            border-color: #8d6e63;
            box-shadow: 0 0 0 3px rgba(141, 110, 99, 0.15);
        }

        .auth-btn {
            background: var(--clr-green);
            color: var(--clr-ink);
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            cursor: pointer;
            font-family: var(--font-body);
            width: 100%;
            margin-top: 10px;
            transition: all 0.15s ease;
        }

        .auth-btn:hover {
            background: var(--clr-green-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }

        .auth-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .auth-alert {
            padding: 12px;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            text-align: center;
        }

        .auth-alert.error {
            background: var(--clr-red);
            color: var(--clr-ink);
            border-color: rgba(239,83,80,0.25);
        }

        .auth-alert.success {
            background: var(--clr-green);
            color: var(--clr-ink);
            border-color: rgba(102,187,106,0.25);
        }

        .back-hub {
            position: absolute;
            top: -15px;
            left: 20px;
            background: var(--clr-yellow);
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            padding: 5px 15px;
            text-decoration: none;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--clr-ink);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.15s ease;
            z-index: 10;
        }

        .back-hub:hover {
            background: var(--clr-yellow-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        
        <div class="logo-area">
            <h1>SKETCH</h1>
            <p style="font-weight:800; opacity:0.75; margin-top: 5px;">Collaborative Whiteboard Studio</p>
        </div>

        <div class="auth-tabs" style="<?php echo in_array($activeTab, ['verify', 'forgot', 'reset'], true) ? 'display: none;' : ''; ?>">
            <button type="button" class="auth-tab <?php echo $activeTab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login')">SIGN IN</button>
            <button type="button" class="auth-tab <?php echo $activeTab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">REGISTER</button>
        </div>

        <?php if ($error !== ''): ?>
            <div class="auth-alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="auth-alert success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="login-form" class="auth-form <?php echo $activeTab === 'login' ? 'active' : ''; ?>" method="POST" action="auth.php">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            
            <div class="input-group">
                <label for="login-username">Username or Email</label>
                <input type="text" id="login-username" name="username_or_email" class="auth-input" required placeholder="Enter username or email">
            </div>

            <div class="input-group">
                <label for="login-password">Password</label>
                <input type="password" id="login-password" name="password" class="auth-input" required placeholder="••••••••">
            </div>

            <div style="text-align: right; margin-top: -5px;">
                <a href="#" onclick="switchTab('forgot'); return false;" style="font-size: 0.8rem; color: var(--clr-ink-light); text-decoration: underline;">Forgot password?</a>
            </div>

            <button type="submit" class="auth-btn">LET'S GO &rarr;</button>
        </form>

        <!-- Register Form -->
        <form id="register-form" class="auth-form <?php echo $activeTab === 'register' ? 'active' : ''; ?>" method="POST" action="auth.php">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-group">
                <label for="reg-username">Username</label>
                <input type="text" id="reg-username" name="username" class="auth-input" required placeholder="Letters, numbers, underscores" pattern="[a-zA-Z0-9_]{3,20}">
            </div>

            <div class="input-group">
                <label for="reg-email">Email Address</label>
                <input type="email" id="reg-email" name="email" class="auth-input" required placeholder="you@example.com">
            </div>

            <div class="input-group">
                <label for="reg-password">Password</label>
                <input type="password" id="reg-password" name="password" class="auth-input" required placeholder="Minimum 6 characters">
            </div>

            <div class="input-group">
                <label for="reg-confirm">Confirm Password</label>
                <input type="password" id="reg-confirm" name="confirm_password" class="auth-input" required placeholder="••••••••">
            </div>

            <button type="submit" class="auth-btn" style="background: var(--clr-orange);">CREATE ACCOUNT</button>
        </form>

        <!-- Verification Form -->
        <form id="verify-form" class="auth-form <?php echo $activeTab === 'verify' ? 'active' : ''; ?>" method="POST" action="auth.php">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-group">
                <label for="verify-code">Verification Code</label>
                <input type="text" id="verify-code" name="code" class="auth-input" required placeholder="6-digit code" pattern="[0-9]{6}" maxlength="6" style="text-align: center; font-size: 1.5rem; letter-spacing: 5px; font-weight: bold;">
                <p style="font-size: 0.8rem; color: var(--clr-ink-light); margin: 5px 0 0 0; text-align: center;">Enter the code sent to your email.</p>
            </div>

            <button type="submit" class="auth-btn" style="background: var(--clr-yellow); color: var(--clr-ink);">VERIFY & REGISTER</button>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="#" onclick="switchTab('register'); return false;" style="font-size: 0.85rem; color: var(--clr-ink-light); text-decoration: underline; font-weight: 700;">Back to Registration</a>
            </div>
        </form>

        <!-- Forgot Password Form -->
        <form id="forgot-form" class="auth-form <?php echo $activeTab === 'forgot' ? 'active' : ''; ?>" method="POST" action="auth.php">
            <input type="hidden" name="action" value="forgot_request">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-group">
                <label for="forgot-email">Email Address</label>
                <input type="email" id="forgot-email" name="email" class="auth-input" required placeholder="you@example.com">
                <p style="font-size: 0.8rem; color: var(--clr-ink-light); margin: 5px 0 0 0;">We will send you a link to reset your password.</p>
            </div>

            <button type="submit" class="auth-btn" style="background: var(--clr-yellow); color: var(--clr-ink);">SEND RESET LINK</button>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="#" onclick="switchTab('login'); return false;" style="font-size: 0.85rem; color: var(--clr-ink-light); text-decoration: underline; font-weight: 700;">Back to Sign In</a>
            </div>
        </form>

        <!-- Reset Password Form -->
        <form id="reset-form" class="auth-form <?php echo $activeTab === 'reset' ? 'active' : ''; ?>" method="POST" action="auth.php">
            <input type="hidden" name="action" value="reset_submit">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="input-group">
                <label for="reset-password">New Password</label>
                <input type="password" id="reset-password" name="password" class="auth-input" required placeholder="Minimum 6 characters">
            </div>

            <div class="input-group">
                <label for="reset-confirm">Confirm Password</label>
                <input type="password" id="reset-confirm" name="confirm_password" class="auth-input" required placeholder="••••••••">
            </div>

            <button type="submit" class="auth-btn" style="background: var(--clr-orange);">RESET PASSWORD</button>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="#" onclick="switchTab('login'); return false;" style="font-size: 0.85rem; color: var(--clr-ink-light); text-decoration: underline; font-weight: 700;">Back to Sign In</a>
            </div>
        </form>
    </div>

    <script>
        function switchTab(tab) {
            // Remove previous error/success alerts to avoid clutter
            const alerts = document.querySelectorAll('.auth-alert');
            alerts.forEach(a => a.remove());

            // Toggle tabs visibility
            const tabsContainer = document.querySelector('.auth-tabs');
            if (['verify', 'forgot', 'reset'].includes(tab)) {
                tabsContainer.style.display = 'none';
            } else {
                tabsContainer.style.display = 'flex';
            }

            // Toggle tab active class
            document.querySelectorAll('.auth-tab').forEach(b => {
                b.classList.toggle('active', b.textContent.toLowerCase().includes(tab === 'login' ? 'in' : 'reg'));
            });

            // Toggle forms visibility
            document.getElementById('login-form').classList.toggle('active', tab === 'login');
            document.getElementById('register-form').classList.toggle('active', tab === 'register');
            document.getElementById('verify-form').classList.toggle('active', tab === 'verify');
            document.getElementById('forgot-form').classList.toggle('active', tab === 'forgot');
            document.getElementById('reset-form').classList.toggle('active', tab === 'reset');
            
            // Focus on first input
            if (tab === 'login') {
                document.getElementById('login-username').focus();
            } else if (tab === 'register') {
                document.getElementById('reg-username').focus();
            } else if (tab === 'verify') {
                document.getElementById('verify-code').focus();
            } else if (tab === 'forgot') {
                document.getElementById('forgot-email').focus();
            } else if (tab === 'reset') {
                document.getElementById('reset-password').focus();
            }
        }
    </script>
</body>
</html>
