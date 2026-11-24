<?php
/**
 * Prototype bootstrap: loads configuration and registers a lightweight,
 * Composer-compatible autoloader for the Operators\ namespace.
 *
 * PHP 7.4 compatible (no PHP 8-only syntax).
 */

$configFile = dirname(__DIR__) . '/config/config.php';
$config = require $configFile;

// Optional local overrides (git-ignored). Kept out of config.php so the same
// tracked defaults work on every server.
$localFile = dirname(__DIR__) . '/config/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

$GLOBALS['GP_CONFIG'] = $config;

if (!empty($GLOBALS['GP_CONFIG']['app']['timezone'])) {
    date_default_timezone_set($GLOBALS['GP_CONFIG']['app']['timezone']);
}

/**
 * Dot-notation access to configuration values.
 *
 * @param string|null $key
 * @param mixed       $default
 * @return mixed
 */
function gp_config($key = null, $default = null)
{
    $config = isset($GLOBALS['GP_CONFIG']) ? $GLOBALS['GP_CONFIG'] : [];

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

/**
 * PSR-4 style autoloader: Operators\Http\HttpClient
 *   -> lib/Http/HttpClient.php
 */
spl_autoload_register(function ($class) {
    $prefix = 'Operators\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

