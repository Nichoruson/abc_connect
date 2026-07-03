<?php
// ============================================================
// ABC Connect — Email Configuration & Helper
// ============================================================

// Provider options: 'log' (for simulated logging) or 'php' (for live php mail() function)
if (!defined('MAIL_PROVIDER')) {
    define('MAIL_PROVIDER', 'log'); 
}

/**
 * Sends an email message to a recipient.
 *
 * @param string $to Recipient email address (e.g. user@example.com)
 * @param string $subject The email subject line
 * @param string $message The email body content
 * @return array ['success' => bool, 'message' => string]
 */
function send_email(string $to, string $subject, string $message): array {
    // Validate email format
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid email address format.'
        ];
    }
    
    // Log Driver (safely writes to logs/mail.log for development/testing)
    if (MAIL_PROVIDER === 'log') {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/mail.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] TO: {$to} | SUBJECT: {$subject}\nMESSAGE:\n{$message}\n" . str_repeat('=', 40) . "\n";
        
        if (file_put_contents($logFile, $logEntry, FILE_APPEND) !== false) {
            return [
                'success' => true,
                'message' => 'Email simulated successfully. Written to logs/mail.log.'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to write to logs/mail.log.'
            ];
        }
    }
    
    // PHP mail() Driver
    if (MAIL_PROVIDER === 'php') {
        $headers = "MIME-Version: 1.0" . "\r\n" .
                   "Content-type: text/html; charset=UTF-8" . "\r\n" .
                   "From: ABC Connect <noreply@abcconnect-bitecenter.com>" . "\r\n" .
                   "Reply-To: noreply@abcconnect-bitecenter.com" . "\r\n" .
                   "X-Mailer: PHP/" . phpversion();
                   
        // Convert plain text newlines to html breaks for safety/readability if content is sent as HTML
        $htmlMessage = nl2br(htmlspecialchars($message));
        
        // Wrap in a basic clean Clinical Calm email template
        $body = "
        <html>
        <head>
          <title>" . htmlspecialchars($subject) . "</title>
          <style>
            body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #0b1c30; padding: 20px; margin: 0; }
            .card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; max-width: 500px; margin: 0 auto; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
            .logo { color: #006b5f; font-size: 20px; font-weight: 700; margin-bottom: 20px; border-bottom: 2px solid #006b5f; padding-bottom: 10px; }
            .content { font-size: 15px; line-height: 1.6; color: #334155; }
            .footer { font-size: 12px; color: #64748b; margin-top: 30px; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 15px; }
          </style>
        </head>
        <body>
          <div class='card'>
            <div class='logo'>ABC Connect Bite Center</div>
            <div class='content'>{$htmlMessage}</div>
            <div class='footer'>This is an automated system email. Please do not reply.</div>
          </div>
        </body>
        </html>
        ";
        
        if (mail($to, $subject, $body, $headers)) {
            return [
                'success' => true,
                'message' => 'Email sent successfully via php mail().'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to send email using PHP mail(). Check SMTP configuration.'
            ];
        }
    }
    
    return [
        'success' => false,
        'message' => 'Invalid email driver configured.'
    ];
}
