<?php
/**
 * Static list of domain accounts allowed to manage operators.
 *
 * Matching is case-insensitive and uses the bare username, so all of these
 * are equivalent for the same account:
 *
 *   'jdoe'
 *   'DOMAIN\jdoe'
 *   'jdoe@domain.local'
 *
 * Accounts not listed here can still authenticate against AD, but cannot open
 * operadores_admin.php or call its write endpoints.
 *
 * You can also append users from the AUTH_USERS environment variable
 * (comma-separated) without editing this file.
 */

return [
    'caldeirag',
    // 'another.user',
];
