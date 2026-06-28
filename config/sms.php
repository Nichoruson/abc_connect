<?php
// ============================================================
// ABC Connect — SMS Configuration & Helper
// ============================================================

// Driver options: 'log' (for testing/development) or 'semaphore' (for production)
if (!defined('SMS_PROVIDER')) {
    define('SMS_PROVIDER', 'log'); 
}

// Semaphore API Key (get from semaphore.co)
if (!defined('SMS_API_KEY')) {
    define('SMS_API_KEY', ''); 
}

// Default sender name registered in your Semaphore account
if (!defined('SMS_SENDER_NAME')) {
    define('SMS_SENDER_NAME', 'Semaphore'); 
}

/**
 * Sends an SMS message to a recipient.
 *
 * @param string $to Recipient mobile number (e.g. 09171234567)
 * @param string $message The SMS message content
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function send_sms(string $to, string $message): array {
    // Sanitize phone number (strip non-numeric characters)
    $cleanNumber = preg_replace('/[^0-9]/', '', $to);
    
    // Validate number length (PH numbers are 11 digits starting with 09)
    if (strlen($cleanNumber) < 10) {
        return [
            'success' => false,
            'message' => 'Invalid phone number length.'
        ];
    }
    
    // Log Driver (safely writes to logs/sms.log for development)
    if (SMS_PROVIDER === 'log') {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/sms.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] TO: {$cleanNumber} | MESSAGE: {$message}\n";
        
        if (file_put_contents($logFile, $logEntry, FILE_APPEND) !== false) {
            return [
                'success' => true,
                'message' => 'SMS simulated successfully. Written to logs/sms.log.',
                'logged' => true
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to write to logs/sms.log.'
            ];
        }
    }
    
    // Semaphore Driver
    if (SMS_PROVIDER === 'semaphore') {
        if (empty(SMS_API_KEY)) {
            return [
                'success' => false,
                'message' => 'Semaphore API Key is not configured in config/sms.php.'
            ];
        }
        
        $ch = curl_init();
        $parameters = [
            'apikey' => SMS_API_KEY,
            'number' => $cleanNumber,
            'message' => $message,
            'sendername' => SMS_SENDER_NAME
        ];
        
        curl_setopt($ch, CURLOPT_URL, 'https://semaphore.co/api/v4/messages');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Recommended for certain local XAMPP environments
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            return [
                'success' => false,
                'message' => 'CURL Error: ' . $err
            ];
        }
        
        $json = json_decode($response, true);
        if ($httpCode === 200 || ($httpCode >= 200 && $httpCode < 300)) {
            return [
                'success' => true,
                'message' => 'SMS successfully sent via Semaphore.',
                'response' => $json
            ];
        }
        
        // Handle failed API response
        $apiError = isset($json['message']) ? $json['message'] : $response;
        return [
            'success' => false,
            'message' => 'Semaphore API Error: ' . $apiError,
            'response' => $response
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Invalid SMS driver configured.'
    ];
}
