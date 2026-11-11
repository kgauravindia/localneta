<?php
// includes/sms_helper.php - Environment-aware OTP SMS Helper for LocalNeta

if (!defined('LOCALNETA_SMS_HELPER_LOADED')) {
    define('LOCALNETA_SMS_HELPER_LOADED', true);
}

function is_local_dev_env(): bool {
    if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') {
        return true;
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]') {
        return true;
    }
    if (str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
        return true;
    }

    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return in_array($remoteAddr, ['127.0.0.1', '::1'], true) && $host === '';
}

/**
 * Dispatch a 6-digit OTP SMS using configured OfferPlant SMS gateway in production,
 * or prepare local-dev delivery safely without external calls on localhost/CLI tests.
 */
function send_otp_sms(string $mobile, string $otp, string $purpose = 'verification'): array {
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
        return [
            'success' => false,
            'channel' => 'validation',
            'message' => 'Please enter a valid 10-digit Indian mobile number.'
        ];
    }

    $localConfig = __DIR__ . '/config.local.php';
    if (is_file($localConfig)) {
        require_once $localConfig;
    }

    $sendLive = (defined('LOCALNETA_SEND_LIVE_SMS') && LOCALNETA_SEND_LIVE_SMS === true)
        || strtolower((string)getenv('LOCALNETA_SEND_LIVE_SMS')) === 'true';

    $smsUrl = defined('LOCALNETA_SMS_URL') ? (string)LOCALNETA_SMS_URL : (string)(getenv('LOCALNETA_SMS_URL') ?: '');
    $smsAuthKey = defined('LOCALNETA_SMS_AUTH_KEY') ? (string)LOCALNETA_SMS_AUTH_KEY : (string)(getenv('LOCALNETA_SMS_AUTH_KEY') ?: '');
    $smsSenderId = defined('LOCALNETA_SMS_SENDER_ID') ? (string)LOCALNETA_SMS_SENDER_ID : (string)(getenv('LOCALNETA_SMS_SENDER_ID') ?: 'LOCNTA');

    if ((is_local_dev_env() && !$sendLive) || $smsUrl === '' || $smsAuthKey === '') {
        return [
            'success' => true,
            'channel' => 'local_dev',
            'otp'     => $otp,
            'message' => 'OTP prepared in local verification mode.'
        ];
    }

    $actionWord = $purpose === 'password_reset' ? 'password reset' : 'account verification';
    $smsText = "Dear User, your LocalNeta OTP for {$actionWord} is {$otp}. Valid for 10 minutes. Do not share with anyone. - OfferPlant";

    $queryParams = http_build_query([
        'AUTH_KEY'      => $smsAuthKey,
        'message'       => $smsText,
        'senderId'      => $smsSenderId,
        'routeId'       => '1',
        'mobileNos'     => $digits,
        'smsContentType'=> 'english',
    ]);

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'channel' => 'curl_missing',
            'message' => 'SMS dispatch extension is unavailable.'
        ];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $smsUrl . '?' . $queryParams,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '' || $httpCode < 200 || $httpCode >= 300) {
        error_log("LocalNeta SMS gateway error ({$httpCode}): {$err}");
        return [
            'success' => false,
            'channel' => 'gateway_error',
            'message' => 'Could not deliver OTP SMS right now. Please retry.'
        ];
    }

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && isset($decoded['responseCode']) && (string)$decoded['responseCode'] !== '3001') {
        error_log('LocalNeta SMS gateway rejection: ' . $raw);
        return [
            'success' => false,
            'channel' => 'gateway_rejected',
            'message' => 'SMS provider declined delivery.'
        ];
    }

    return [
        'success' => true,
        'channel' => 'sms',
        'message' => 'OTP sent via SMS.'
    ];
}

