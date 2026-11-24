<?php
/**
 * Shared helpers for the Operators BFF.
 *
 * PHP 7.4 compatible.
 */

if (!function_exists('gp_config')) {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * @param array<string,mixed> $cfg
 */
function gp_file_cache(array $cfg): Operators\Cache\FileCache
{
    $ttl = isset($cfg['cache_ttl']) ? (int) $cfg['cache_ttl'] : 60;
    $refresh = isset($_GET['__refresh']);

    return new Operators\Cache\FileCache(
        (string) $cfg['cache_dir'],
        $refresh ? 0 : $ttl,
        $ttl
    );
}

/**
 * @param mixed $payload
 */
function json_response($payload, int $status = 200): void
{
    $delay = array_key_exists('__delay', $_GET) ? (int) $_GET['__delay'] : 0;
    if ($delay > 0) {
        usleep($delay * 1000);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => true, 'status' => $status, 'message' => $message], $status);
}

function maybe_fail(): void
{
    if (isset($_GET['__fail']) && $_GET['__fail'] !== '0') {
        json_error('Simulated upstream API failure (mock).', 503);
    }
}

/**
 * Server-side clock info for the board.
 *
 * @return array<string,mixed>
 */
function server_time_meta(): array
{
    $now = time();
    $midnight = strtotime('tomorrow');

    $clockOffset = (int) date('Z', $now);
    $app = isset($GLOBALS['GP_CONFIG']['app']) && is_array($GLOBALS['GP_CONFIG']['app'])
        ? $GLOBALS['GP_CONFIG']['app']
        : [];
    $clockTimezone = !empty($app['clock_timezone']) ? (string) $app['clock_timezone'] : 'Europe/Lisbon';
    try {
        $tz = new DateTimeZone($clockTimezone);
        $clockOffset = (new DateTime('now', $tz))->getOffset();
    } catch (Exception $e) {
        // Fall back to the app timezone offset.
    }

    return [
        'server_date'         => date('Y-m-d', $now),
        'server_time'         => date('c', $now),
        'server_epoch_ms'     => $now * 1000,
        'server_tz_offset'    => (int) date('Z', $now),
        'server_clock_offset' => $clockOffset,
        'seconds_to_midnight' => $midnight !== false ? max(0, $midnight - $now) : null,
    ];
}
