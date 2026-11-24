<?php
/**
 * End the operator-management session.
 */

require __DIR__ . '/lib/auth.php';

auth_start_session();
auth_logout();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: login.php?logged_out=1');
exit;
