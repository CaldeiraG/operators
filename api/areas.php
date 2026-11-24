<?php
/**
 * GET api/areas.php
 *
 * Returns active FAssy areas for the operator management dropdown.
 * Calls {intranet}/areas and falls back to areas_response.json.
 *
 * Debug overrides: ?__mode=auto|remote|mock, ?__refresh=1, ?__fail=1.
 */

declare(strict_types=1);

require __DIR__ . '/_helpers.php';

maybe_fail();

$cfg = gp_config('intranet_api');
if (!is_array($cfg)) {
    $cfg = [];
}

$mode = isset($_GET['__mode']) ? (string) $_GET['__mode'] : (string) ($cfg['mode'] ?? 'auto');
if (!in_array($mode, ['auto', 'remote', 'mock'], true)) {
    $mode = 'auto';
}

$areas = null;
$source = 'remote';
$upstream = null;

if ($mode !== 'mock') {
    $url = rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/' . ltrim((string) ($cfg['areas_path'] ?? 'areas'), '/');
    $cache = gp_file_cache($cfg);
    $cacheKey = 'areas|' . $url;
    $cached = $cache->get($cacheKey);

    if (is_array($cached)) {
        $areas = $cached;
        $source = 'cache';
    } else {
        $http = new Operators\Http\HttpClient(
            (int) ($cfg['timeout'] ?? 8),
            (int) ($cfg['connect_timeout'] ?? 3)
        );
        $started = microtime(true);
        $res = $http->getJson($url, [], max(1, (int) ($cfg['retries'] ?? 1)));
        $upstream = [
            'url'      => $res['url'],
            'status'   => $res['status'],
            'ok'       => !empty($res['ok']),
            'error'    => $res['error'] ?? null,
            'took_ms'  => round((microtime(true) - $started) * 1000, 1),
            'transport' => $res['transport'],
        ];

        if (!empty($res['ok'])) {
            $areas = areas_normalize($res['json'], $cfg);
            $cache->set($cacheKey, $areas);
        } else {
            $fallback = (bool) ($cfg['fallback_to_file'] ?? true);
            if ($mode === 'remote' || !$fallback) {
                $detail = isset($res['error']) ? (string) $res['error'] : 'unknown_error';
                json_error('Areas API unavailable (' . $detail . ')', 502);
            }
        }
    }
}

if ($areas === null) {
    $source = 'file-fallback';
    $file = (string) ($cfg['fallback_file'] ?? '');
    $decoded = ($file !== '' && is_file($file))
        ? json_decode((string) file_get_contents($file), true)
        : null;
    $areas = areas_normalize($decoded, $cfg);
}

$response = [
    'areas'  => array_values($areas),
    'source' => $source,
];
if (isset($_GET['__debug'])) {
    $response['upstream'] = $upstream;
}

json_response($response);

// ---------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------

/**
 * @param mixed $json
 * @param array<string,mixed> $cfg
 * @return array<int,array<string,string>>
 */
function areas_normalize($json, array $cfg): array
{
    $rows = areas_rows($json);
    $type = strtolower((string) ($cfg['area_type'] ?? ''));
    $filterActive = (bool) ($cfg['filter_active'] ?? true);
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowType = strtolower(areas_pick($row, ['type', 'tipo', 'area_type']));
        if ($type !== '' && $rowType !== $type) {
            continue;
        }
        if ($filterActive && !areas_is_active($row)) {
            continue;
        }

        $name = trim((string) areas_pick($row, ['name', 'nome', 'area_name', 'areanome']));
        if ($name === '') {
            continue;
        }
        $id = trim((string) areas_pick($row, ['id', 'area_id', 'number']));

        $out[] = [
            'id'   => $id !== '' ? $id : $name,
            'name' => $name,
            'type' => (string) areas_pick($row, ['type', 'tipo', 'area_type']),
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });

    return $out;
}

/**
 * @param mixed $json
 * @return array<int,array<string,mixed>>
 */
function areas_rows($json): array
{
    if (!is_array($json) || $json === []) {
        return [];
    }

    foreach (['areas', 'data', 'rows', 'result', 'results', 'items'] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) {
            if (areas_looks_like_row($json[$key])) {
                return [$json[$key]];
            }
            return array_values($json[$key]);
        }
    }

    if (areas_looks_like_row($json)) {
        return [$json];
    }

    $rows = [];
    foreach ($json as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** @param array<string,mixed> $row */
function areas_looks_like_row(array $row): bool
{
    return areas_pick($row, ['name', 'nome', 'area_name', 'areanome']) !== '';
}

/**
 * @param array<string,mixed> $row
 * @param string[] $keys
 */
function areas_pick(array $row, array $keys): string
{
    $wanted = [];
    foreach ($keys as $key) {
        $wanted[areas_key($key)] = true;
    }
    foreach ($row as $key => $value) {
        if (isset($wanted[areas_key($key)]) && $value !== null && !is_array($value)) {
            return trim((string) $value);
        }
    }
    return '';
}

function areas_key($key): string
{
    return strtolower((string) preg_replace('/[\s_\-\.]+/', '', (string) $key));
}

/** @param array<string,mixed> $row */
function areas_is_active(array $row): bool
{
    $value = null;
    $found = false;
    foreach ($row as $key => $item) {
        $k = areas_key((string) $key);
        if ($k === 'isactive' || $k === 'active') {
            $value = $item;
            $found = true;
            break;
        }
    }
    if (!$found) {
        return true;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}
