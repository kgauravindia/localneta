<?php
/**
 * includes/sms_helper.php - SMS & OTP Gateway Helper for LocalNeta
 * Powered by OfferPlant SMS Engine (Adapted from BiharElection.com)
 *
 * Sender ID: LONETA
 * Template Name: LONETA_OTP (TRAI DLT Template ID: 1277179438546154323)
 * Category: Service Implicit / Communication/Broadcasting/Entertainment/IT
 */

if (!defined('LOCALNETA_SMS_HELPER_LOADED')) {
    define('LOCALNETA_SMS_HELPER_LOADED', true);
}

// Automatically load local credentials if present (GITIGNORED)
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// Default Configuration Constants
if (!defined('SMS_SENDER_ID')) {
    define('SMS_SENDER_ID', 'LONETA');
}
if (!defined('LOCALNETA_SMS_SENDER_ID')) {
    define('LOCALNETA_SMS_SENDER_ID', SMS_SENDER_ID);
}
if (!defined('SMS_TEMPLATE_NAME')) {
    define('SMS_TEMPLATE_NAME', 'LONETA_OTP');
}
if (!defined('SMS_TEMPLATE_ID')) {
    define('SMS_TEMPLATE_ID', '1277179438546154323');
}
if (!defined('LOCALNETA_SMS_DLT_TE_ID')) {
    define('LOCALNETA_SMS_DLT_TE_ID', SMS_TEMPLATE_ID);
}
if (!defined('SMS_API_URL')) {
    define('SMS_API_URL', 'http://msg.morg.in/rest/services/sendSMS/sendGroupSms');
}
if (!defined('LOCALNETA_SMS_URL')) {
    define('LOCALNETA_SMS_URL', SMS_API_URL);
}
if (!defined('SMS_AUTH_KEY')) {
    define('SMS_AUTH_KEY', defined('LOCALNETA_SMS_AUTH_KEY') ? (string)LOCALNETA_SMS_AUTH_KEY : (string)(getenv('LOCALNETA_SMS_AUTH_KEY') ?: ''));
}
if (!defined('LOCALNETA_SMS_AUTH_KEY')) {
    define('LOCALNETA_SMS_AUTH_KEY', SMS_AUTH_KEY);
}
if (!defined('SMS_OTP_TEMPLATE')) {
    define('SMS_OTP_TEMPLATE', "Dear {#alphanumeric#}, \n Your EVC / OTP / Password for Local Neta is {#alphanumeric#} \n Visit localneta.com \n Regards\n LONETA\n OfferPlant");
}

/**
 * Determine if current execution is in local development / test mode
 */
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
 * Send bulk or single SMS through OfferPlant HTTP gateway
 *
 * @param string|array $mobile_list Comma-separated string or array of 10-digit mobile numbers
 * @param string $sms Content of the SMS message
 * @param int $count Number of messages for status reporting
 * @param string $smstype 'english' or 'unicode'
 * @param string $template_id Optional DLT Template ID
 * @return array Status array with status ('success'|'error'), count, msg, and optional response
 */
function bulk_msg($mobile_list, $sms, $count = 1, $smstype = 'english', $template_id = '') {
    $auth_key = defined('SMS_AUTH_KEY') && (string)SMS_AUTH_KEY !== ''
        ? (string)SMS_AUTH_KEY
        : (defined('LOCALNETA_SMS_AUTH_KEY') ? (string)LOCALNETA_SMS_AUTH_KEY : (string)(getenv('LOCALNETA_SMS_AUTH_KEY') ?: ''));

    $sender_id = defined('SMS_SENDER_ID') && (string)SMS_SENDER_ID !== ''
        ? (string)SMS_SENDER_ID
        : (defined('LOCALNETA_SMS_SENDER_ID') ? (string)LOCALNETA_SMS_SENDER_ID : 'LONETA');

    $api_url = defined('SMS_API_URL') && (string)SMS_API_URL !== ''
        ? (string)SMS_API_URL
        : (defined('LOCALNETA_SMS_URL') ? (string)LOCALNETA_SMS_URL : 'http://msg.morg.in/rest/services/sendSMS/sendGroupSms');

    if (is_array($mobile_list)) {
        $mobile_list = implode(',', $mobile_list);
    }

    // Sanitize mobile numbers to valid 10-digit Indian numbers
    $rawNumbers = explode(',', (string)$mobile_list);
    $cleanList = [];
    foreach ($rawNumbers as $rawMobile) {
        $digits = preg_replace('/\D+/', '', $rawMobile) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^[6-9]\d{9}$/', $digits)) {
            $cleanList[] = $digits;
        }
    }

    if (empty($cleanList)) {
        return [
            'status' => 'error',
            'count'  => 0,
            'msg'    => 'No valid 10-digit Indian mobile numbers provided.',
            'raw'    => null,
        ];
    }

    $mobileNumbers = implode(',', $cleanList);
    $actualCount = count($cleanList);

    $data = [
        'smsContent'     => $sms,
        'groupId'        => '',
        'routeId'        => 1,
        'mobileNumbers'  => $mobileNumbers,
        'senderId'       => $sender_id,
        'signature'      => '',
        'smsContentType' => $smstype,
    ];

    $activeTemplateId = $template_id !== ''
        ? (string)$template_id
        : (defined('SMS_TEMPLATE_ID') ? (string)SMS_TEMPLATE_ID : (defined('LOCALNETA_SMS_DLT_TE_ID') ? (string)LOCALNETA_SMS_DLT_TE_ID : ''));

    if ($activeTemplateId !== '') {
        $data['template_id'] = $activeTemplateId;
        $data['dltTemplateId'] = $activeTemplateId;
    }

    if (!function_exists('curl_init')) {
        return [
            'status' => 'error',
            'count'  => 0,
            'msg'    => 'cURL extension is not installed.',
            'raw'    => null,
        ];
    }

    $text_sms = json_encode($data);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $api_url . "?AUTH_KEY=" . urlencode($auth_key),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => "",
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => "POST",
        CURLOPT_POSTFIELDS     => $text_sms,
        CURLOPT_HTTPHEADER     => [
            "Cache-Control: no-cache",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $res = [];
    $jsonResp = is_string($response) ? json_decode($response, true) : null;

    if ($err) {
        $res['status']    = 'error';
        $res['count']     = 0;
        $res['msg']       = "cURL Error: " . $err;
        $res['raw']       = null;
        $res['http_code'] = $httpCode;
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        $res['status']    = 'error';
        $res['count']     = 0;
        $res['msg']       = "HTTP Gateway Error: " . $httpCode;
        $res['raw']       = $response;
        $res['http_code'] = $httpCode;
    } elseif (is_array($jsonResp) && isset($jsonResp['responseCode']) && (string)$jsonResp['responseCode'] !== '3001') {
        $res['status']    = 'error';
        $res['count']     = 0;
        $res['msg']       = "Gateway Error (" . $jsonResp['responseCode'] . "): " . ($jsonResp['response'] ?? 'SMS dispatch failed');
        $res['raw']       = $response;
        $res['http_code'] = $httpCode;
    } else {
        $res['status']    = 'success';
        $res['count']     = $actualCount;
        $res['msg']       = $actualCount . " SMS Sent Successfully";
        $res['http_code'] = $httpCode;
        $res['raw']       = $response;
    }

    return $res;
}

/**
 * Send OTP / EVC / Password SMS using approved TRAI DLT template (LONETA_OTP)
 *
 * Approved DLT Template (1277179438546154323):
 * Dear {#alphanumeric#}, 
 *  Your EVC / OTP / Password for Local Neta is {#alphanumeric#} 
 *  Visit localneta.com 
 *  Regards
 *  LONETA
 *  OfferPlant
 *
 * @param string $mobile Recipient mobile number (10 digits)
 * @param string $name User name or recipient descriptor (1st {#alphanumeric#})
 * @param string|int $otp OTP / Security code / Password (2nd {#alphanumeric#})
 * @return array
 */
function sendOTP($mobile, $name, $otp) {
    $displayName = !empty(trim((string)$name)) ? trim((string)$name) : 'User';
    // Exact whitespace & line break matching approved TRAI DLT registration
    $message = "Dear " . $displayName . ", \n Your EVC / OTP / Password for Local Neta is " . $otp . " \n Visit localneta.com \n Regards\n LONETA\n OfferPlant";

    $templateId = defined('SMS_TEMPLATE_ID') ? (string)SMS_TEMPLATE_ID : '1277179438546154323';
    return bulk_msg($mobile, $message, 1, 'english', $templateId);
}

/**
 * Send a custom direct SMS through OfferPlant gateway
 *
 * @param string $mobile
 * @param string $message
 * @return array
 */
function sendCustomSMS($mobile, $message) {
    return bulk_msg($mobile, $message);
}

/**
 * Dispatch a 6-digit OTP SMS for LocalNeta user authentication with local-dev fallback
 *
 * @param string $mobile
 * @param string $otp
 * @param string $purpose ('verification', 'registration', 'password_reset')
 * @param string|null $name Optional user display name
 * @return array
 */
function send_otp_sms(string $mobile, string $otp, string $purpose = 'verification', ?string $name = null): array {
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

    $sendLive = (defined('LOCALNETA_SEND_LIVE_SMS') && LOCALNETA_SEND_LIVE_SMS === true)
        || strtolower((string)getenv('LOCALNETA_SEND_LIVE_SMS')) === 'true';

    $authKey = defined('SMS_AUTH_KEY') && (string)SMS_AUTH_KEY !== ''
        ? (string)SMS_AUTH_KEY
        : (defined('LOCALNETA_SMS_AUTH_KEY') ? (string)LOCALNETA_SMS_AUTH_KEY : (string)(getenv('LOCALNETA_SMS_AUTH_KEY') ?: ''));

    // Local dev mode without live dispatch
    if ((is_local_dev_env() && !$sendLive) || $authKey === '') {
        return [
            'success' => true,
            'channel' => 'local_dev',
            'otp'     => $otp,
            'message' => 'OTP prepared in local verification mode.'
        ];
    }

    $displayName = !empty(trim((string)$name)) ? trim((string)$name) : 'User';
    $res = sendOTP($digits, $displayName, $otp);

    if (($res['status'] ?? '') === 'success') {
        return [
            'success' => true,
            'channel' => 'sms',
            'message' => 'OTP sent via SMS.',
            'raw'     => $res['raw'] ?? null,
        ];
    }

    return [
        'success' => false,
        'channel' => 'gateway_rejected',
        'message' => $res['msg'] ?? 'SMS provider declined delivery.',
        'raw'     => $res['raw'] ?? null,
    ];
}
