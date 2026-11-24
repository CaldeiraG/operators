<?php
/**
 * Copy to config/config.local.php and fill in the real values.
 * config.local.php is git-ignored.
 */

return [
    'app' => [
        'env'      => 'production',
        'timezone' => 'Europe/Lisbon',
    ],

    'auth' => [
        'ldap' => [
            'host'        => 'ldap://dc01.domain.local',
            'port'        => 389,
            'domain'      => 'DOMAIN',
            'bind_format' => 'downlevel', // downlevel|upn
            'use_tls'     => false,
            'timeout'     => 5,
        ],
        'session_ttl' => 28800,
        'cookie_path' => '/',
        'cookie_secure' => 'auto',
        'debug' => false,
    ],

    'operadores_api' => [
        'base_url' => 'http://localhost:4000/intranet',
        'path'     => 'operators',
        // Prefix for relative photo paths, e.g. 'http://<host>/photos'.
        'image_base_url' => '',
    ],

    'intranet_api' => [
        'base_url' => 'http://localhost:4000/intranet',
        'areas_path' => 'areas',
    ],
];
