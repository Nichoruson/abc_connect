<?php
// ============================================================
// ABC Connect — Forgot Password (forgot_password.php)
// Password Recovery via Email OTP Verification
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/mail.php';

// Restrict access to mobile app webview (matching register.php/login.php)
if (!is_mobile_app()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8"/>
      <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
      <title>Access Restricted | ABC Connect</title>
      <link rel="preconnect" href="https://fonts.googleapis.com"/>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
      <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
      <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
      <style>
        body { margin: 0; min-height: 100vh; background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%); display: flex; align-items: center; justify-content: center; font-family: 'Inter', sans-serif; }
        .restrict-card { background: white; border: 1.5px solid var(--outline-variant); border-radius: var(--radius-xl); padding: var(--space-xl); max-width: 440px; width: 100%; text-align: center; box-shadow: var(--shadow-card); margin: var(--space-md); }
        .restrict-icon { width: 64px; height: 64px; background: rgba(239, 68, 68, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-lg); }
        .restrict-icon .material-symbols-outlined { color: var(--error); font-size: 32px; }
      </style>
    </head>
    <body>
      <div class="restrict-card">
        <div class="restrict-icon">
          <span class="material-symbols-outlined">block</span>
        </div>
        <h2 style="font-weight: 800; color: var(--on-surface); margin-bottom: var(--space-sm);">Mobile Access Only</h2>
        <p style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.6; margin-bottom: var(--space-lg);">
          Password recovery is strictly restricted to the official <strong>ABC Connect</strong> mobile application.
        </p>
        <a href="<?= APP_BASE ?>/login.php" class="btn btn-surface btn-full" style="justify-content: center; text-decoration: none;">Go to Sign In</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

if (is_patient_logged_in()) {
    redirect(APP_BASE . '/patient/dashboard.php');
}

// Reset workflow if requested
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['forgot_pwd']);
    redirect(APP_BASE . '/forgot_password.php');
}

// Determine current step
$step = 1;
if (isset($_SESSION['forgot_pwd'])) {
    if ($_SESSION['forgot_pwd']['verified'] === true) {
        $step = 3;
    } else {
        $step = 2;
    }
}

$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = :e LIMIT 1");
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'Email address not found. Please verify and try again.';
            } else {
                // Generate OTP
                $otp = (string)rand(100000, 999999);
                $_SESSION['forgot_pwd'] = [
                    'email'    => $email,
                    'otp'      => $otp,
                    'expires'  => time() + 300, // 5 minutes validity
                    'verified' => false
                ];
                
                // Send email
                $subject = "ABC Connect — Password Reset OTP";
                $message = "Hello " . htmlspecialchars($user['full_name']) . ",\r\n\r\n" .
                           "You have requested to reset your password. Please use the following 6-digit One-Time PIN (OTP) to confirm your identity:\r\n\r\n" .
                           "OTP Code: " . $otp . "\r\n\r\n" .
                           "This code is valid for 5 minutes. If you did not initiate this request, please secure your account immediately.\r\n\r\n" .
                           "Best regards,\r\nABC Connect Support Team";
                           
                $res = send_email($email, $subject, $message);
                if ($res['success']) {
                    $step = 2;
                    $info = 'Verification code successfully sent to your email!';
                } else {
                    $error = 'Failed to send OTP code: ' . $res['message'];
                    unset($_SESSION['forgot_pwd']);
                }
            }
        }
    } 
    
    elseif ($action === 'verify_otp') {
        $otpInput = trim($_POST['otp'] ?? '');
        if (!$otpInput) {
            $error = 'Please enter the 6-digit OTP code.';
        } elseif (!isset($_SESSION['forgot_pwd'])) {
            $error = 'Session expired. Please start over.';
            $step = 1;
        } else {
            $session = $_SESSION['forgot_pwd'];
            if (time() > $session['expires']) {
                $error = 'Verification code has expired. Please request a new one.';
                unset($_SESSION['forgot_pwd']);
                $step = 1;
            } elseif ($otpInput !== $session['otp']) {
                $error = 'Invalid verification code. Please check and try again.';
            } else {
                $_SESSION['forgot_pwd']['verified'] = true;
                $step = 3;
                $info = 'Identity verified successfully. Please enter your new password.';
            }
        }
    } 
    
    elseif ($action === 'reset_password') {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        
        if (!$password || !$confirm) {
            $error = 'Please fill in all fields.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (!isset($_SESSION['forgot_pwd']) || $_SESSION['forgot_pwd']['verified'] !== true) {
            $error = 'Unauthorized access. Please start over.';
            unset($_SESSION['forgot_pwd']);
            $step = 1;
        } else {
            $email = $_SESSION['forgot_pwd']['email'];
            $hash  = password_hash($password, PASSWORD_BCRYPT);
            
            try {
                $db = getDB();
                $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE email = :e");
                $stmt->execute([':hash' => $hash, ':e' => $email]);
                
                unset($_SESSION['forgot_pwd']);
                flash('success', 'Your password has been reset successfully. Please log in.');
                redirect(APP_BASE . '/login.php');
            } catch (Exception $e) {
                $error = 'Failed to reset password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/patient.css"/>
</head>
<body>

<div class="auth-container">
  <div class="auth-card animate-slide-up" style="max-width:420px; width:100%;">
    <div class="auth-logo" style="display: flex; align-items: center; gap: var(--space-md); margin-bottom: var(--space-lg);">
      <img src="<?= APP_BASE ?>/assets/logo.png" alt="ABC Connect Logo" style="width: 44px; height: 44px; object-fit: contain;"/>
      <div>
        <h1 style="font-size:20px;color:var(--primary);margin:0; font-weight:800;">ABC Connect</h1>
        <p style="font-size:12px;color:var(--on-surface-variant);margin:0">Account Recovery</p>
      </div>
    </div>

    <!-- Step Progress Indicator -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--space-lg); background:var(--surface-container); padding:var(--space-sm) var(--space-md); border-radius:var(--radius-lg); font-size:12px; font-weight:600; color:var(--on-surface-variant);">
      <span style="<?= $step === 1 ? 'color:var(--primary); font-weight:700;' : '' ?>">1. Email</span>
      <span class="material-symbols-outlined" style="font-size:14px;">chevron_right</span>
      <span style="<?= $step === 2 ? 'color:var(--primary); font-weight:700;' : '' ?>">2. OTP Verify</span>
      <span class="material-symbols-outlined" style="font-size:14px;">chevron_right</span>
      <span style="<?= $step === 3 ? 'color:var(--primary); font-weight:700;' : '' ?>">3. Reset</span>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-md)">
      <span class="material-symbols-outlined" style="color:var(--error)">error</span>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($info): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-md); background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--success); display: flex; align-items: center; gap: var(--space-xs); padding: var(--space-md); border-radius: var(--radius-md);">
      <span class="material-symbols-outlined">check_circle</span>
      <span style="font-size:14px;"><?= htmlspecialchars($info) ?></span>
    </div>
    <?php endif; ?>

    <!-- STEP 1: ENTER EMAIL -->
    <?php if ($step === 1): ?>
    <p style="font-size:14px; color:var(--on-surface-variant); line-height:1.5; margin-bottom:var(--space-md);">
      Enter your registered email address below. We'll send you a 6-digit OTP code to verify your identity.
    </p>
    <form method="POST" action="" novalidate>
      <input type="hidden" name="action" value="send_otp">
      <div style="display:flex; flex-direction:column; gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input class="form-input" type="email" id="email" name="email" 
                 placeholder="yourname@gmail.com" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit">
          <span class="material-symbols-outlined">send</span>
          Send Verification Code
        </button>
      </div>
    </form>
    <?php endif; ?>

    <!-- STEP 2: ENTER OTP -->
    <?php if ($step === 2): ?>
    <p style="font-size:14px; color:var(--on-surface-variant); line-height:1.5; margin-bottom:var(--space-md);">
      A verification code was sent to <strong><?= htmlspecialchars($_SESSION['forgot_pwd']['email'] ?? '') ?></strong>. Please enter the 6-digit code below to confirm.
    </p>
    <form method="POST" action="" novalidate>
      <input type="hidden" name="action" value="verify_otp">
      <div style="display:flex; flex-direction:column; gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="otp">Enter 6-Digit PIN</label>
          <input class="form-input" type="text" id="otp" name="otp" 
                 placeholder="XXXXXX" maxlength="6" required autocomplete="off"
                 style="text-align:center; font-size:24px; font-weight:700; letter-spacing:8px; font-family:monospace;"/>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit">
          <span class="material-symbols-outlined">verified_user</span>
          Verify OTP Code
        </button>
      </div>
    </form>
    <div style="display:flex; justify-content:space-between; margin-top:var(--space-md); font-size:13px;">
      <a href="?reset=1" style="color:var(--on-surface-variant); text-decoration:none; display:flex; align-items:center; gap:2px;">
        <span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span> Back
      </a>
      <a href="?reset=1" style="color:var(--primary); font-weight:600; text-decoration:none;">Resend Code</a>
    </div>
    <?php endif; ?>

    <!-- STEP 3: RESET PASSWORD -->
    <?php if ($step === 3): ?>
    <p style="font-size:14px; color:var(--on-surface-variant); line-height:1.5; margin-bottom:var(--space-md);">
      Identity verified. Please enter and confirm your new password below.
    </p>
    <form method="POST" action="" novalidate>
      <input type="hidden" name="action" value="reset_password">
      <div style="display:flex; flex-direction:column; gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="password">New Password</label>
          <input class="form-input" type="password" id="password" name="password" 
                 placeholder="Min. 6 characters" required/>
        </div>
        <div class="form-group">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input class="form-input" type="password" id="confirm_password" name="confirm_password" 
                 placeholder="Re-enter new password" required/>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit">
          <span class="material-symbols-outlined">lock_reset</span>
          Reset Password & Login
        </button>
      </div>
    </form>
    <?php endif; ?>

    <div style="text-align:center;margin-top:var(--space-lg);font-size:14px;">
      <a href="<?= APP_BASE ?>/login.php" style="color:var(--on-surface-variant);text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
        <span class="material-symbols-outlined" style="font-size:16px;">keyboard_backspace</span>
        Back to Sign In
      </a>
    </div>
  </div>
</div>

</body>
</html>
