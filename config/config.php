<?php
/**
 * Operators - central configuration (tracked, contains NO secrets).
 *
 * Resolution order:
 *   1. safe defaults below
 *   2. environment variables
 *   3. config/config.local.php overrides (git-ignored, highest priority)
 *
 * Authorized login accounts live in config/auth_users.php (or AUTH_USERS).
 */

$root = dirname(__DIR__);

return [
    'app' => [
        'env'            => getenv('APP_ENV') ?: 'production',
        'timezone'       => getenv('APP_TIMEZONE') ?: 'Europe/Lisbon',
        'clock_timezone' => getenv('APP_CLOCK_TIMEZONE') ?: 'Europe/Lisbon',
    ],

    // Operator board + management endpoints.
    'operadores_api' => [
        // auto   : call the API, fall back to operadores_response.json
        // remote : call the API only (502 on failure)
        // mock   : read operadores_response.json only
        'mode'             => getenv('OPERADORES_API_MODE') ?: 'auto',
        'base_url'         => getenv('OPERADORES_API_BASE') ?: (getenv('INTRANET_API_BASE') ?: 'http://localhost:4000/intranet'),
        'path'             => getenv('OPERADORES_API_PATH') ?: 'operators',
        'line_param'       => getenv('OPERADORES_API_LINE_PARAM') ?: 'line_id',
        'forward_params'   => ['line_id', 'area_id', 'date'],
        'image_base_url'   => getenv('OPERADORES_IMAGE_BASE') ?: '',
        'timeout'          => (int) (getenv('OPERADORES_API_TIMEOUT') ?: 8),
        'connect_timeout'  => (int) (getenv('OPERADORES_API_CONNECT_TIMEOUT') ?: 3),
        'retries'          => (int) (getenv('OPERADORES_API_RETRIES') ?: 1),
        'cache_ttl'        => (int) (getenv('OPERADORES_API_CACHE_TTL') ?: 60),
        'cache_dir'        => $root . '/cache',
        'fallback_to_file' => (getenv('OPERADORES_API_FALLBACK') ?: 'true') !== 'false',
        'file'             => $root . '/operadores_response.json',

        // Writes (operadores_admin.php).
        // create_path empty -> reuse `path`; delete_path empty -> `path/{id}`.
        'write_enabled'    => (getenv('OPERADORES_API_WRITE') ?: 'true') !== 'false',
        'create_method'    => getenv('OPERADORES_API_CREATE_METHOD') ?: 'POST',
        'create_path'      => getenv('OPERADORES_API_CREATE_PATH') ?: '',
        'delete_method'    => getenv('OPERADORES_API_DELETE_METHOD') ?: 'DELETE',
        'delete_path'      => getenv('OPERADORES_API_DELETE_PATH') ?: '',
    ],

    // Areas for the management dropdown.
    'intranet_api' => [
        'mode'            => getenv('INTRANET_API_MODE') ?: 'auto',
        'base_url'        => getenv('INTRANET_API_BASE') ?: 'http://localhost:4000/intranet',
        'areas_path'      => getenv('INTRANET_AREAS_PATH') ?: 'areas',
        'area_type'       => 'FAssy',
        'filter_active'   => true,
        'timeout'         => (int) (getenv('INTRANET_API_TIMEOUT') ?: 8),
        'connect_timeout' => (int) (getenv('INTRANET_API_CONNECT_TIMEOUT') ?: 3),
        'retries'         => (int) (getenv('INTRANET_API_RETRIES') ?: 1),
        'cache_ttl'       => (int) (getenv('INTRANET_API_CACHE_TTL') ?: 300),
        'cache_dir'       => $root . '/cache',
        'fallback_to_file' => (getenv('INTRANET_API_FALLBACK') ?: 'true') !== 'false',
        'fallback_file'   => $root . '/areas_response.json',
    ],

    // Login for operadores_admin.php + operator writes.
    'auth' => [
        'ldap' => [
            // Use ldap://host with StartTLS, or ldaps://host on port 636.
            'host'        => getenv('AD_LDAP_HOST') ?: 'ad.hanonsystems',
            'port'        => (int) (getenv('AD_LDAP_PORT') ?: 389),
            // NetBIOS name for downlevel (DOMAIN\user) or DNS suffix for UPN.
            'domain'      => getenv('AD_DOMAIN') ?: '@hanonsystems',
            'bind_format' => getenv('AD_BIND_FORMAT') ?: 'DOMAIN',
            'use_tls'     => (getenv('AD_LDAP_TLS') ?: 'false') === 'true',
            'timeout'     => (int) (getenv('AD_LDAP_TIMEOUT') ?: 5),
        ],
        'session_name'  => getenv('AUTH_SESSION_NAME') ?: 'OPERATORS_SESSID',
        'session_ttl'   => (int) (getenv('AUTH_SESSION_TTL') ?: 28800),
        'cookie_path'   => getenv('AUTH_COOKIE_PATH') ?: '/',
        'cookie_secure' => getenv('AUTH_COOKIE_SECURE') ?: 'auto',
        'debug'         => (getenv('AUTH_DEBUG') ?: 'false') === 'true',
    ],
];

