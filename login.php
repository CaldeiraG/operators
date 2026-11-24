<?php
/**
 * Login for operator management (Windows AD / LDAP).
 *
 * Credentials are validated with ldap_validate() in lib/auth.php and the
 * account must be listed in config/auth_users.php (or AUTH_USERS).
 */

require __DIR__ . '/lib/auth.php';

auth_start_session();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$next = auth_safe_next(isset($_POST['next']) ? $_POST['next'] : (isset($_GET['next']) ? $_GET['next'] : 'admin.php'));
$username = '';
$error = '';
$sessionOk = session_status() === PHP_SESSION_ACTIVE;
$loggedOut = isset($_GET['logged_out']);

if (!$sessionOk) {
    $error = 'Não foi possível iniciar a sessão no servidor. Contacte o administrador.';
} elseif (auth_current_user() !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $next);
    exit;
}

if ($sessionOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_scalar($_POST['username'])
        ? trim((string) $_POST['username']) : '';
    $password = isset($_POST['password']) && is_scalar($_POST['password'])
        ? (string) $_POST['password'] : '';
    $csrf = isset($_POST['csrf']) && is_scalar($_POST['csrf'])
        ? (string) $_POST['csrf'] : '';

    if (!auth_validate_csrf($csrf)) {
        $error = 'Sessão expirada. Tente novamente.';
    } elseif ($username === '' || $password === '') {
        $error = 'Preencha o utilizador e a password.';
    } elseif (!ldap_validate($username, $password)) {
        $code = function_exists('ldap_validate_error') ? (string) ldap_validate_error() : '';
        if (auth_debug()) {
            $error = 'Não foi possível validar as credenciais no Active Directory (' . ($code !== '' ? $code : 'unknown') . ').';
        } elseif ($code === '' || $code === 'empty' || $code === 'bind') {
            $error = 'Credenciais inválidas.';
        } else {
            $error = 'Não foi possível contactar o Active Directory. Contacte o administrador.';
        }
    } elseif (!auth_is_authorized($username)) {
        $error = 'A conta não está autorizada a gerir operadores.';
    } else {
        auth_login($username);
        header('Location: ' . $next);
        exit;
    }
}

$csrfToken = $sessionOk ? auth_csrf_token() : '';
$usernameEsc = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
$nextEsc = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
$csrfEsc = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar - Operadores</title>

    <link rel="stylesheet" type="text/css" href="libraries/bootstrap/bootstrap.min.css"/>
    <link rel="stylesheet" type="text/css"
          href="assets/app.css?v=<?php echo is_file(__DIR__ . '/assets/app.css') ? (string) filemtime(__DIR__ . '/assets/app.css') : '1'; ?>"/>
    <link rel="stylesheet" type="text/css"
          href="assets/login.css?v=<?php echo is_file(__DIR__ . '/assets/login.css') ? (string) filemtime(__DIR__ . '/assets/login.css') : '1'; ?>"/>
</head>
<body>

<div class="login-wrap">
    <div class="login-card card">
        <div class="login-logo">
            <img alt="logo" src="logo.png">
        </div>

        <div class="login-title">Operadores</div>
        <div class="login-subtitle">Gestão de operadores</div>

        <?php if ($loggedOut && $error === ''): ?>
            <div class="alert alert-success">Sessão terminada.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="" autocomplete="on">
            <input type="hidden" name="csrf" value="<?php echo $csrfEsc; ?>">
            <input type="hidden" name="next" value="<?php echo $nextEsc; ?>">

            <div class="form-group">
                <label for="username">CDSID</label>
                <input type="text" class="form-control" id="username" name="username"
                       value="<?php echo $usernameEsc; ?>" autocomplete="username"
                       placeholder="CDSID" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-success btn-block">Entrar</button>
        </form>

        <a class="login-back" href="operadores.php">Voltar ao quadro</a>
    </div>

    <div class="login-footer">
        Acesso restrito. Utilize a sua conta de domínio.
    </div>
</div>

</body>
</html>

