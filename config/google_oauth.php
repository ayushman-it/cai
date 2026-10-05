<?php
/**
 * CUBOIDPILOT — GOOGLE OAUTH 2.0 SINGLE SIGN-ON CONFIGURATION
 * Allows team members and workspace owners to log in and sign up with their office Google accounts.
 */

require_once __DIR__ . '/db.php';

// 1. Google Cloud Console OAuth Credentials
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

/**
 * Dynamically determine the authorized redirect URI
 */
function getGoogleRedirectUri(): string {
    if (!empty(getenv('GOOGLE_REDIRECT_URI'))) {
        return getenv('GOOGLE_REDIRECT_URI');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if (str_contains($host, 'cuboidsoft.in')) {
        return "https://{$host}/google_callback.php";
    }

    return "{$protocol}{$host}/cuboidpilot/google_callback.php";
}

/**
 * Generate Google OAuth 2.0 Authorization URL
 * 
 * @param string $source 'login' or 'join'
 * @return string
 */
function getGoogleAuthUrl(string $source = 'login'): string {
    $redirectUri = getGoogleRedirectUri();
    $state = base64_encode(json_encode([
        'source' => $source,
        'nonce'  => bin2hex(random_bytes(8)),
        'time'   => time()
    ]));

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['oauth_state'] = $state;
    }

    $params = [
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'access_type'           => 'offline',
        'prompt'                => 'select_account',
        'include_granted_scopes'=> 'true',
        'state'                 => $state
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}
