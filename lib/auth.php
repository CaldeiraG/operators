<?php
/**
 * Session authentication against Windows AD (LDAP) + static authorization.
 *
 * The password is validated with an LDAP simple bind against the configured
 * domain controller. Only usernames listed in config/auth_users.php (or the
 * AUTH_USERS environment variable) are allowed to manage operators.
 *
 * PHP 7.4 compatible; requires the PHP LDAP extension (php_ldap).
 */

if (!function_exists('gp_config')) {
    require_once __DIR__ . '/bootstrap.php';
}

// ---------------------------------------------------------------------------
// LDAP
// ---------------------------------------------------------------------------

if (!function_exists('ldap_validate')) {
    /**
     * Validate a domain account with an LDAP bind.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    function ldap_validate($username, $password)
    {
        $GLOBALS['GP_LDAP_ERROR'] = '';

        if (is_array($username) || is_array($password)) {
            $GLOBALS['GP_LDAP_ERROR'] = 'bind';
            return false;
        }

        $username = trim((string) $username);
        $password = (string) $password;

        if ($username === '' || $password === '') {
            $GLOBALS['GP_LDAP_ERROR'] = 'empty';
            return false;
        }
        if (strpos($password, "\0") !== false) {
            $GLOBALS['GP_LDAP_ERROR'] = 'bind';
            return false;
        }
        if (!function_exists('ldap_connect')) {
            $GLOBALS['GP_LDAP_ERROR'] = 'no_ldap';
            @error_log('operators auth: PHP LDAP extension is not enabled');
            return false;
        }

        $host = trim((string) gp_config('auth.ldap.host', ''));
        $port = (int) gp_config('auth.ldap.port', 389);
        $domain = trim((string) gp_config('auth.ldap.domain', ''));
        $format = strtolower(trim((string) gp_config('auth.ldap.bind_format', 'downlevel')));
        $useTls = (bool) gp_config('auth.ldap.use_tls', false);
        $timeout = max(1, (int) gp_config('auth.ldap.timeout', 5));

        if ($host === '') {
            $GLOBALS['GP_LDAP_ERROR'] = 'config';
            @error_log('operators auth: auth.ldap.host is empty');
            return false;
        }
        if (strpos($host, '://') === false) {
            $host = 'ldap://' . $host;
        }

        $conn = @ldap_connect($host, $port);
        if (!$conn) {
            $GLOBALS['GP_LDAP_ERROR'] = 'connect';
            @error_log('operators auth: cannot create LDAP connection to ' . $host);
            return false;
        }

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        }
        if (defined('LDAP_OPT_TIMEOUT')) {
            @ldap_set_option($conn, LDAP_OPT_TIMEOUT, $timeout);
        }

        if ($useTls && stripos($host, 'ldaps://') !== 0) {
            if (!@ldap_start_tls($conn)) {
                $GLOBALS['GP_LDAP_ERROR'] = 'tls';
                @error_log('operators auth: LDAP StartTLS failed for ' . $host);
                @ldap_unbind($conn);
                return false;
            }
        }

        $bindName = auth_ldap_bind_name($username, $domain, $format);
        $ok = @ldap_bind($conn, $bindName, $password);

        if (!$ok) {
            $errno = (int) @ldap_errno($conn);
            // 81 = LDAP_SERVER_DOWN, 91 = LDAP_CONNECT_ERROR.
            $GLOBALS['GP_LDAP_ERROR'] = ($errno === 81 || $errno === 91) ? 'connect' : 'bind';
            @ldap_unbind($conn);
            return false;
        }

        @ldap_unbind($conn);
        return true;
    }
}

if (!function_exists('ldap_validate_error')) {
    /**
     * Last LDAP validation error code: empty, no_ldap, config, connect, tls,
     * bind, or an empty string on success/unknown.
     *
     * @return string
     */
    function ldap_validate_error()
    {
        return isset($GLOBALS['GP_LDAP_ERROR']) ? (string) $GLOBALS['GP_LDAP_ERROR'] : '';
    }
}

/**
 * Build the AD bind name from the configured format.
 *
 * @param string $username
 * @param string $domain
 * @param string $format downlevel|upn
 * @return string
 */
function auth_ldap_bind_name($username, $domain, $format)
{
    $username = trim((string) $username);
    if ($username === '') {
        return '';
    }
    if ($domain === '' || strpos($username, '\\') !== false || strpos($username, '@') !== false) {
        return $username;
    }
    return strtolower((string) $format) === 'upn'
        ? $username . '@' . $domain
        : $domain . '\\' . $username;
}

// ---------------------------------------------------------------------------
// Authorization list
// ---------------------------------------------------------------------------

/**
 * Normalise a domain account to the bare username used by the static list.
 *
 * @param string $username
 * @return string
 */
function auth_normalize_username($username)
{
    if (is_array($username) || is_object($username)) {
        return '';
    }
    $value = trim((string) $username);
    if ($value === '') {
        return '';
    }
    if (strpos($value, '\\') !== false) {
        $value = substr($value, strrpos($value, '\\') + 1);
    }
    if (strpos($value, '@') !== false) {
        $value = substr($value, 0, strpos($value, '@'));
    }
    return strtolower(trim($value));
}

/**
 * True when the account is in the static list or AUTH_USERS.
 *
 * @param string $username
 * @return bool
 */
function auth_is_authorized($username)
{
    $normalized = auth_normalize_username($username);
    if ($normalized === '') {
        return false;
    }

    $allowed = [];
    $file = dirname(__DIR__) . '/config/auth_users.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $allowed = $loaded;
        }
    }

    $env = (string) getenv('AUTH_USERS');
    if ($env !== '') {
        $allowed = array_merge($allowed, explode(',', $env));
    }

    foreach ($allowed as $entry) {
        if (auth_normalize_username($entry) === $normalized) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

/** Session lifetime in seconds. */
function auth_ttl()
{
    return max(300, (int) gp_config('auth.session_ttl', 28800));
}

/** Whether the session cookie should be marked Secure. */
function auth_cookie_secure()
{
    $mode = strtolower(trim((string) gp_config('auth.cookie_secure', 'auto')));
    if ($mode === '1' || $mode === 'true' || $mode === 'yes') {
        return true;
    }
    if ($mode === '0' || $mode === 'false' || $mode === 'no') {
        return false;
    }

    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return false;
}

/** Start the hardened session once. */
function auth_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $name = trim((string) gp_config('auth.session_name', 'OPERATORS_SESSID'));
    if ($name !== '') {
        @session_name($name);
    }

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.gc_maxlifetime', (string) max(1440, auth_ttl()));

    $secure = auth_cookie_secure();
    $path = (string) gp_config('auth.cookie_path', '/');
    if ($path === '') {
        $path = '/';
    }

    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        @session_set_cookie_params(0, $path, '', $secure, true);
    }

    @session_start();
}

/** Current authenticated username, or '' when the session is absent/expired. */
function auth_current_user()
{
    auth_start_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $user = isset($_SESSION['gp_auth_user']) ? (string) $_SESSION['gp_auth_user'] : '';
    $expires = isset($_SESSION['gp_auth_expires']) ? (int) $_SESSION['gp_auth_expires'] : 0;

    if ($user === '' || $expires < time() || !auth_is_authorized($user)) {
        if ($user !== '') {
            auth_logout(false);
        }
        return '';
    }

    // Sliding expiration: active administrators stay logged in.
    $_SESSION['gp_auth_expires'] = time() + auth_ttl();
    return $user;
}

/** Mark a user as logged in and prevent session fixation. */
function auth_login($username)
{
    auth_start_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    @session_regenerate_id(true);
    $_SESSION['gp_auth_user'] = auth_normalize_username($username);
    $_SESSION['gp_auth_time'] = time();
    $_SESSION['gp_auth_expires'] = time() + auth_ttl();
    unset($_SESSION['gp_auth_csrf']);
}

/** Clear the authenticated session (optionally destroy it entirely). */
function auth_logout($destroy = true)
{
    auth_start_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['gp_auth_user'], $_SESSION['gp_auth_time'], $_SESSION['gp_auth_expires']);
        if ($destroy) {
            $_SESSION = [];
            if ((bool) ini_get('session.use_cookies')) {
                $path = (string) gp_config('auth.cookie_path', '/');
                @setcookie(session_name(), '', time() - 42000, $path !== '' ? $path : '/');
            }
            @session_destroy();
        }
    }
}

/** Page guard: return the authenticated user or redirect to login.php. */
function auth_require_login()
{
    $user = auth_current_user();
    if ($user !== '') {
        return $user;
    }

    $next = auth_current_url();
    if (!headers_sent()) {
        header('Location: login.php?next=' . rawurlencode($next));
    }
    exit;
}

/** API guard: return the authenticated user or emit a JSON 401. */
function auth_require_api()
{
    $user = auth_current_user();
    if ($user !== '') {
        return $user;
    }

    if (!headers_sent()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'error'   => true,
        'status'  => 401,
        'message' => 'Authentication required.',
    ]);
    exit;
}

/** API guard for state-changing requests. */
function auth_require_csrf_api()
{
    $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($token === '' && isset($_POST['csrf'])) {
        $token = (string) $_POST['csrf'];
    }

    if (!auth_validate_csrf($token)) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode([
            'error'   => true,
            'status'  => 403,
            'message' => 'Invalid or expired session token.',
        ]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// CSRF + redirects
// ---------------------------------------------------------------------------

/** Session CSRF token used by the login form and the admin page. */
function auth_csrf_token()
{
    auth_start_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['gp_auth_csrf']) || !is_string($_SESSION['gp_auth_csrf'])) {
        $_SESSION['gp_auth_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['gp_auth_csrf'];
}

/** Constant-time CSRF validation. */
function auth_validate_csrf($token)
{
    auth_start_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $stored = isset($_SESSION['gp_auth_csrf']) ? (string) $_SESSION['gp_auth_csrf'] : '';
    return $stored !== '' && is_string($token) && hash_equals($stored, $token);
}

/** Keep post-login redirects on this host and away from login/logout. */
function auth_safe_next($next)
{
    if (is_array($next) || is_object($next)) {
        return 'operadores_admin.php';
    }
    $next = trim((string) $next);
    if ($next === '') {
        return 'operadores_admin.php';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $next)) {
        return 'operadores_admin.php';
    }
    if (strpos($next, '//') === 0
        || strpos($next, '\\') !== false
        || strpos($next, "\n") !== false
        || strpos($next, "\r") !== false) {
        return 'operadores_admin.php';
    }

    $parts = parse_url($next);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return 'operadores_admin.php';
    }
    if (preg_match('#(^|/)(login|logout)\.php#i', $next)) {
        return 'operadores_admin.php';
    }

    return $next;
}

/** Current request URI, safe for a post-login redirect. */
function auth_current_url()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/operadores_admin.php';
    }
    return auth_safe_next($uri);
}

/** Show LDAP error codes in the login page (never passwords). */
function auth_debug()
{
    return (bool) gp_config('auth.debug', false);
}


