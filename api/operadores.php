<?php
/**
 * GET api/operadores.php?line_id=52&shift=1T
 *
 * BFF for the operator board (operadores.php). It calls the operators endpoint
 * configured under `operadores_api` and normalises the response into one flat
 * list plus the shift currently in progress.
 *
 * Upstream contract (tolerant):
 *
 *   GET {base_url}/{path}
 *
 *   [
 *     { "id": 1, "name": "Ana Silva", "image": "https://.../ana.jpg",
 *       "shift": "1T", "line": "GEN5 Line E", "role": "Montagem" }
 *   ]
 *
 * Also accepted: {operadores:[...]}, {operators:[...]}, {data:[...]},
 * {rows:[...]}, a single row object, or { "4T": [...], "1T": [...] }.
 * Column aliases are handled in operadores_normalize_row().
 *
 * Shift boundaries (plant time, Europe/Lisbon by default):
 *   4T  01:00 - 07:59  (7 h)
 *   1T  08:00 - 16:29  (8.5 h)
 *   2T  16:30 - 00:59  (8.5 h, crosses midnight)
 *
 * Debug overrides: ?__mode=auto|remote|mock, ?__refresh=1, ?__fail=1.
 * PHP 7.4 compatible.
 */

declare(strict_types=1);

require __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../lib/auth.php';

maybe_fail();

$cfg = gp_config('operadores_api');
if (!is_array($cfg)) {
    $cfg = [];
}

$mode = isset($_GET['__mode']) ? (string) $_GET['__mode'] : (string) ($cfg['mode'] ?? 'auto');
if (!in_array($mode, ['auto', 'remote', 'mock'], true)) {
    $mode = 'auto';
}

// The public BFF surface is:
//   GET         -> list (operadores.php)
//   POST        -> create (operadores_admin.php)
//   PATCH/PUT   -> replace photo (operadores_admin.php)
//   DELETE      -> remove (operadores_admin.php)
// The upstream methods/paths are configurable under `operadores_api`.
$httpMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if (in_array($httpMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    operadores_handle_write($httpMethod, $cfg, $mode);
}

$lineId = isset($_GET['line_id']) ? trim((string) $_GET['line_id']) : '';
$shiftFilter = isset($_GET['shift']) ? operadores_normalize_shift($_GET['shift']) : null;

$rows = null;
$source = 'remote';
$upstream = null;

if ($mode !== 'mock') {
    $pathTemplate = (string) ($cfg['path'] ?? 'operadores');
    $pathHasLine = strpos($pathTemplate, '{line_id}') !== false;
    $path = str_replace('{line_id}', rawurlencode($lineId), $pathTemplate);
    $url = rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/' . ltrim($path, '/');

    $query = [];
    if ($lineId !== '' && !$pathHasLine) {
        $query[(string) ($cfg['line_param'] ?? 'line_id')] = $lineId;
    }

    $forward = isset($cfg['forward_params']) && is_array($cfg['forward_params'])
        ? $cfg['forward_params']
        : [];
    foreach ($forward as $param) {
        $param = (string) $param;
        if ($param === '' || $param === 'line_id' || !isset($_GET[$param])) {
            continue;
        }
        if ($_GET[$param] !== '') {
            $query[$param] = (string) $_GET[$param];
        }
    }

    $cache = gp_file_cache($cfg);
    $cacheKey = 'operadores|v' . operadores_cache_version($cfg) . '|' . $url . '|' . http_build_query($query);
    $cached = $cache->get($cacheKey);

    if (is_array($cached)) {
        $rows = $cached;
        $source = 'cache';
    } else {
        $http = new Operators\Http\HttpClient(
            (int) ($cfg['timeout'] ?? 8),
            (int) ($cfg['connect_timeout'] ?? 3)
        );
        $started = microtime(true);
        $res = $http->getJson($url, $query, max(1, (int) ($cfg['retries'] ?? 1)));
        $upstream = [
            'url'      => $res['url'],
            'status'   => $res['status'],
            'ok'       => !empty($res['ok']),
            'error'    => isset($res['error']) ? $res['error'] : null,
            'took_ms'  => round((microtime(true) - $started) * 1000, 1),
            'transport' => $res['transport'],
        ];

        if (!empty($res['ok'])) {
            $rows = operadores_rows_from_payload($res['json']);
            $cache->set($cacheKey, $rows);
        } else {
            $fallback = (bool) ($cfg['fallback_to_file'] ?? true);
            if ($mode === 'remote' || !$fallback) {
                $detail = isset($res['error']) ? (string) $res['error'] : 'unknown_error';
                json_error('Operators API unavailable (' . $detail . ')', 502);
            }
        }
    }
}

if ($rows === null) {
    $source = 'file-fallback';
    $file = isset($cfg['file']) ? (string) $cfg['file'] : '';
    $decoded = ($file !== '' && is_file($file))
        ? json_decode((string) file_get_contents($file), true)
        : null;
    $rows = operadores_rows_from_payload($decoded);
}

$operators = [];
$seen = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $operator = operadores_normalize_row($row, $cfg);
    if ($operator === null) {
        continue;
    }

    if ($shiftFilter !== null && $operator['shift'] !== $shiftFilter) {
        continue;
    }

    // De-duplicate by id when the upstream sends the same person twice (for
    // example one row per operation). Rows without an id are kept.
    if ($operator['id'] !== '') {
        $key = 'id:' . $operator['id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
    }

    $operators[] = $operator;
}

$order = ['4T' => 0, '1T' => 1, '2T' => 2, '' => 3];
usort($operators, static function (array $a, array $b) use ($order): int {
    $sa = isset($order[$a['shift']]) ? $order[$a['shift']] : 3;
    $sb = isset($order[$b['shift']]) ? $order[$b['shift']] : 3;
    if ($sa !== $sb) {
        return $sa - $sb;
    }
    return strcasecmp($a['name'], $b['name']);
});

$counts = ['4T' => 0, '1T' => 0, '2T' => 0, 'unassigned' => 0];
foreach ($operators as $operator) {
    if (isset($counts[$operator['shift']])) {
        $counts[$operator['shift']]++;
    } else {
        $counts['unassigned']++;
    }
}
$counts['total'] = count($operators);

$shiftMeta = operadores_shift_meta();
$shiftDefs = [
    ['key' => '4T', 'label' => '4º Turno', 'start' => '01:00', 'end' => '07:59', 'hours' => 7.0],
    ['key' => '1T', 'label' => '1º Turno', 'start' => '08:00', 'end' => '16:29', 'hours' => 8.5],
    ['key' => '2T', 'label' => '2º Turno', 'start' => '16:30', 'end' => '00:59', 'hours' => 8.5],
];
foreach ($shiftDefs as $i => $def) {
    $shiftDefs[$i]['count'] = $counts[$def['key']];
    $shiftDefs[$i]['active'] = ($def['key'] === $shiftMeta['current_shift']);
    $shiftDefs[$i]['range'] = $def['start'] . ' - ' . $def['end'];
}

$meta = [
    'source'          => $source,
    'generated_at'    => date('c'),
    'line_id'         => $lineId,
    'shift_filter'    => $shiftFilter,
    'timezone'        => $shiftMeta['timezone'],
    'timezone_offset' => $shiftMeta['timezone_offset'],
    'production_date' => $shiftMeta['production_date'],
] + $shiftMeta + server_time_meta();

$response = [
    'meta'      => $meta,
    'counts'    => $counts,
    'shifts'    => $shiftDefs,
    'operators' => $operators,
];

if (isset($_GET['__debug'])) {
    $response['meta']['upstream'] = $upstream;
}

json_response($response);

// ---------------------------------------------------------------------------
// Normalisation helpers
// ---------------------------------------------------------------------------

/**
 * Turn any accepted upstream payload into a list of row arrays.
 *
 * @param mixed $json
 * @return array<int,array<string,mixed>>
 */
function operadores_rows_from_payload($json): array
{
    if (!is_array($json) || $json === []) {
        return [];
    }

    foreach ([
        'operadores', 'operators', 'data', 'dados', 'rows', 'records',
        'recordset', 'result', 'results', 'items', 'lista',
    ] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) {
            if (operadores_looks_like_row($json[$key])) {
                return [$json[$key]];
            }
            return array_values($json[$key]);
        }
    }

    if (operadores_looks_like_row($json)) {
        return [$json];
    }

    // {"4T":[...],"1T":[...]} — inject the key as the shift when the row has none.
    $grouped = [];
    $isGrouped = true;
    foreach ($json as $key => $value) {
        if (!is_array($value) || operadores_normalize_shift($key) === null) {
            $isGrouped = false;
            break;
        }
    }
    if ($isGrouped) {
        foreach ($json as $shiftKey => $items) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    if (!operadores_has_value($item, ['shift', 'turno', 'shift_name', 'turno_nome', 'shiftname'])) {
                        $item['shift'] = $shiftKey;
                    }
                    $grouped[] = $item;
                }
            }
        }
        return $grouped;
    }

    $rows = [];
    foreach ($json as $item) {
        if (is_array($item)) {
            $rows[] = $item;
        }
    }
    return $rows;
}

/**
 * Normalise a single upstream row.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $cfg
 * @return array<string,string>|null
 */
function operadores_normalize_row(array $row, array $cfg): ?array
{
    $name = operadores_pick($row, [
        'name', 'nome', 'operator', 'operador', 'employee', 'colaborador',
        'full_name', 'fullname', 'nome_completo', 'operator_name', 'employee_name',
    ]);

    $nameMissing = ($name === null)
        || (is_string($name) && trim($name) === '')
        || (is_array($name) && !$name);

    if ($nameMissing
        && (operadores_pick($row, ['first_name', 'firstname']) !== null
            || operadores_pick($row, ['last_name', 'lastname']) !== null)) {
        $parts = [
            (string) operadores_pick($row, ['first_name', 'firstname']),
            (string) operadores_pick($row, ['last_name', 'lastname']),
        ];
        $name = trim(implode(' ', array_filter($parts, static function ($v): bool {
            return trim($v) !== '';
        })));
    }

    if (is_array($name)) {
        $first = operadores_pick($name, ['first', 'first_name', 'firstname', 'given']);
        $last = operadores_pick($name, ['last', 'last_name', 'lastname', 'surname', 'family']);
        if ($first !== null || $last !== null) {
            $name = trim((string) $first . ' ' . (string) $last);
        } else {
            $picked = operadores_pick($name, ['full', 'name', 'nome', 'full_name', 'value', 'label']);
            $name = is_scalar($picked) ? (string) $picked : '';
        }
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') {
        return null;
    }

    $image = operadores_pick($row, [
        'image', 'imagem', 'photo', 'foto', 'picture', 'avatar', 'img',
        'image_url', 'imageurl', 'photo_url', 'photourl', 'foto_url', 'fotourl',
        'image_path', 'photo_path', 'picture_path', 'file', 'filename',
    ]);
    $image = operadores_normalize_image($image, (string) ($cfg['image_base_url'] ?? ''));

    $rawShift = operadores_pick($row, [
        'shift', 'turno', 'shift_name', 'turno_nome', 'shiftname', 'turnonome',
        'shift_id', 'turno_id', 'shift_desc', 'turno_desc',
    ]);
    $shift = operadores_normalize_shift($rawShift);

    if ($shift === null && isset($row['shift']) && is_array($row['shift'])) {
        $shift = operadores_normalize_shift(operadores_pick($row['shift'], ['name', 'nome', 'label']));
    }

    $id = operadores_pick($row, [
        'id', 'employee_id', 'employeeid', 'employee_number', 'employeenumber',
        'operator_id', 'operatorid', 'number', 'numero', 'ssno', 'badge',
    ]);
    if (is_array($id)) {
        $id = operadores_pick($id, ['id', 'value', 'number']);
    }

    $line = operadores_pick($row, [
        'line', 'linha', 'line_name', 'linename', 'linha_nome', 'linhanome', 'line_id',
    ]);
    if (is_array($line)) {
        $line = operadores_pick($line, ['name', 'nome', 'label', 'id']);
    }

    $role = operadores_pick($row, [
        'role', 'funcao', 'função', 'function', 'position', 'operation',
        'operacao', 'operação', 'posto', 'job', 'task',
    ]);
    if (is_array($role)) {
        $role = operadores_pick($role, ['name', 'nome', 'label']);
    }

    $area = operadores_pick($row, [
        'area', 'area_name', 'areaname', 'area_nome', 'areanome',
        'area_desc', 'area_id',
    ]);
    if (is_array($area)) {
        $area = operadores_pick($area, ['name', 'nome', 'label', 'id']);
    }

    return [
        'id'        => is_scalar($id) ? trim((string) $id) : '',
        'name'      => $name,
        'image'     => $image,
        'shift'     => $shift === null ? '' : $shift,
        'shift_raw' => is_scalar($rawShift) ? trim((string) $rawShift) : '',
        'line'      => is_scalar($line) ? trim((string) $line) : '',
        'role'      => is_scalar($role) ? trim((string) $role) : '',
        'area'      => is_scalar($area) ? trim((string) $area) : '',
    ];
}

/**
 * Case/space/underscore-insensitive first non-empty value among $keys.
 *
 * @param array<string,mixed> $row
 * @param string[]            $keys
 * @return mixed|null
 */
function operadores_pick(array $row, array $keys)
{
    $wanted = [];
    foreach ($keys as $key) {
        $wanted[operadores_key($key)] = true;
    }

    foreach ($row as $key => $value) {
        if (!isset($wanted[operadores_key($key)])) {
            continue;
        }
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        return $value;
    }

    return null;
}

/** Lower-case key with separators removed. */
function operadores_key($key): string
{
    return strtolower((string) preg_replace('/[\s_\-\.]+/', '', (string) $key));
}

/** True when the row has any of the requested keys with a non-empty value. */
function operadores_has_value(array $row, array $keys): bool
{
    return operadores_pick($row, $keys) !== null;
}

/** True when the array looks like a single operator row. */
function operadores_looks_like_row(array $row): bool
{
    return operadores_has_value($row, [
        'name', 'nome', 'operator', 'operador', 'employee', 'colaborador',
        'full_name', 'fullname', 'operator_name', 'employee_name',
    ]);
}

/**
 * Map a raw shift value to 4T / 1T / 2T. The plant labels the night shift
 * "4T"; historical extracts and the schedule API use 3 for the same shift, so
 * 3 / 3T are accepted as aliases.
 *
 * @param mixed $raw
 * @return string|null
 */
function operadores_normalize_shift($raw)
{
    if ($raw === null || $raw === '' || is_array($raw) || is_object($raw)) {
        return null;
    }

    if (is_numeric($raw)) {
        $number = (int) $raw;
        if ($number === 4 || $number === 3) {
            return '4T';
        }
        if ($number === 1) {
            return '1T';
        }
        if ($number === 2) {
            return '2T';
        }
    }

    $value = strtoupper(trim((string) $raw));
    $value = (string) preg_replace('/\s+/', ' ', $value);
    $compact = (string) preg_replace('/[^A-Z0-9]/', '', $value);

    $map = [
        '4T' => '4T', 'T4' => '4T', '4' => '4T', '04' => '4T',
        '4TURNO' => '4T', 'TURNO4' => '4T',
        '3T' => '4T', 'T3' => '4T', '3' => '4T', '03' => '4T',
        '3TURNO' => '4T', 'TURNO3' => '4T',
        'NOITE' => '4T', 'MADRUGADA' => '4T', 'NIGHT' => '4T',
        '1T' => '1T', 'T1' => '1T', '1' => '1T', '01' => '1T',
        '1TURNO' => '1T', 'TURNO1' => '1T',
        'MANHA' => '1T', 'MANH' => '1T', 'MORNING' => '1T', 'DIURNO' => '1T',
        '2T' => '2T', 'T2' => '2T', '2' => '2T', '02' => '2T',
        '2TURNO' => '2T', 'TURNO2' => '2T',
        'TARDE' => '2T', 'AFTERNOON' => '2T', 'EVENING' => '2T',
    ];

    if (isset($map[$compact])) {
        return $map[$compact];
    }

    if (preg_match('/(^|[^0-9])([1234])\s*T/', $value, $match)) {
        return operadores_normalize_shift($match[2]);
    }
    if (preg_match('/T\s*([1234])/', $value, $match)) {
        return operadores_normalize_shift($match[1]);
    }

    return null;
}

/**
 * Build a usable image URL. Base64 blobs are turned into data URIs; relative
 * paths are prefixed with `operadores_api.image_base_url` when configured.
 *
 * @param mixed $value
 * @param string $baseUrl
 * @return string
 */
function operadores_normalize_image($value, string $baseUrl): string
{
    if (is_array($value)) {
        // Node-style {type:"Buffer",data:[...]} or a bare byte array.
        $isBuffer = false;
        if (isset($value['type']) && $value['type'] === 'Buffer' && isset($value['data'])) {
            $isBuffer = true;
            $value = $value['data'];
        }
        if ($isBuffer && is_string($value) && !preg_match('#^data:image/#i', $value)) {
            return 'data:image/jpeg;base64,' . preg_replace('/\s+/', '', $value);
        }
        if (is_array($value)) {
            if (isset($value[0]) && is_numeric($value[0])) {
                $bytes = '';
                foreach ($value as $byte) {
                    $bytes .= chr(max(0, min(255, (int) $byte)));
                }
                return 'data:image/jpeg;base64,' . base64_encode($bytes);
            }
            $picked = operadores_pick($value, ['url', 'link', 'path', 'src', 'file', 'data']);
            if ($picked === null) {
                return '';
            }
            return operadores_normalize_image($picked, $baseUrl);
        }
    }
    if ($value === null) {
        return '';
    }

    $image = trim((string) $value);
    if ($image === '') {
        return '';
    }

    if (preg_match('#^data:image/#i', $image)) {
        return $image;
    }

    // Long un-prefixed base64 payloads (SQL Server varbinary -> base64 string).
    if (strlen($image) > 256 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $image)) {
        return 'data:image/jpeg;base64,' . preg_replace('/\s+/', '', $image);
    }

    if (preg_match('#^https?://#i', $image) || strpos($image, '//') === 0) {
        return $image;
    }

    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/') . '/' . ltrim($image, '/');
    }

    return $image;
}

/**
 * Current shift + boundaries in the plant clock. The night shift crosses
 * midnight, so at 00:30 the active shift is 2T of the previous production day.
 *
 * @return array<string,mixed>
 */
function operadores_shift_meta(): array
{
    $timezone = (string) gp_config('app.clock_timezone', 'Europe/Lisbon');
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone(date_default_timezone_get());
        $timezone = date_default_timezone_get();
    }

    $now = new DateTime('now', $tz);
    $nowTs = $now->getTimestamp();
    $minuteOfDay = ((int) $now->format('G')) * 60 + (int) $now->format('i');
    $today = $now->format('Y-m-d');
    $yesterday = (new DateTime('yesterday', $tz))->format('Y-m-d');
    $tomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');

    $at = static function (string $date, string $time) use ($tz): DateTime {
        return new DateTime($date . ' ' . $time . ':00', $tz);
    };

    $today0100 = $at($today, '01:00');
    $today0800 = $at($today, '08:00');
    $today1630 = $at($today, '16:30');
    $tomorrow0100 = $at($tomorrow, '01:00');
    $yesterday1630 = $at($yesterday, '16:30');

    if ($minuteOfDay >= 60 && $minuteOfDay < 480) {
        $currentShift = '4T';
        $start = $today0100;
        $end = $today0800;
        $nextShift = '1T';
        $nextStart = $today0800;
        $productionDate = $today;
    } elseif ($minuteOfDay >= 480 && $minuteOfDay < 990) {
        $currentShift = '1T';
        $start = $today0800;
        $end = $today1630;
        $nextShift = '2T';
        $nextStart = $today1630;
        $productionDate = $today;
    } else {
        $currentShift = '2T';
        if ($minuteOfDay >= 990) {
            $start = $today1630;
            $end = $tomorrow0100;
            $nextShift = '4T';
            $nextStart = $tomorrow0100;
            $productionDate = $today;
        } else {
            $start = $yesterday1630;
            $end = $today0100;
            $nextShift = '4T';
            $nextStart = $today0100;
            $productionDate = $yesterday;
        }
    }

    $startTs = $start->getTimestamp();
    $endTs = $end->getTimestamp();
    $nextTs = $nextStart->getTimestamp();
    $duration = max(1, $endTs - $startTs);
    $elapsed = max(0, min($duration, $nowTs - $startTs));

    $ranges = [
        '4T' => ['start' => '01:00', 'end' => '07:59', 'hours' => 7.0],
        '1T' => ['start' => '08:00', 'end' => '16:29', 'hours' => 8.5],
        '2T' => ['start' => '16:30', 'end' => '00:59', 'hours' => 8.5],
    ];

    return [
        'timezone'                  => $timezone,
        'timezone_offset'           => $now->getOffset(),
        'production_date'           => $productionDate,
        'current_shift'             => $currentShift,
        'current_shift_range'       => $ranges[$currentShift]['start'] . ' - ' . $ranges[$currentShift]['end'],
        'current_shift_hours'       => $ranges[$currentShift]['hours'],
        'shift_start'               => $ranges[$currentShift]['start'],
        'shift_end'                 => $ranges[$currentShift]['end'],
        'shift_start_epoch_ms'      => $startTs * 1000,
        'shift_end_epoch_ms'        => $endTs * 1000,
        'shift_elapsed_seconds'     => $elapsed,
        'shift_duration_seconds'    => $duration,
        'next_shift'                => $nextShift,
        'next_shift_start'          => $ranges[$nextShift]['start'],
        'next_shift_start_epoch_ms' => $nextTs * 1000,
        'seconds_to_next_shift'     => max(0, $nextTs - $nowTs),
    ];
}

// ---------------------------------------------------------------------------
// Writes (operadores_admin.php)
// ---------------------------------------------------------------------------

/**
 * Route a write request to the configured upstream endpoint.
 *
 * @param string $method POST / PUT / PATCH / DELETE
 * @param array<string,mixed> $cfg
 * @param string $mode
 * @return void
 */
function operadores_handle_write(string $method, array $cfg, string $mode): void
{
    auth_require_api();
    auth_require_csrf_api();

    if ($mode === 'mock') {
        json_error('Writes are disabled in mock mode.', 501);
    }
    if (empty($cfg['write_enabled'])) {
        json_error('Operator writes are disabled.', 403);
    }

    $body = operadores_request_body();

    if ($method === 'DELETE') {
        operadores_delete($cfg, $body);
        return;
    }
    if ($method === 'PUT' || $method === 'PATCH') {
        operadores_update_image($cfg, $body);
        return;
    }

    operadores_create($cfg, $body);
}

/**
 * Read a JSON or form-encoded request body, plus ?id= for DELETE.
 *
 * @return array<string,mixed>
 */
function operadores_request_body(): array
{
    $body = [];
    $raw = @file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    if (isset($_POST) && is_array($_POST) && $_POST) {
        $body = array_replace($body, $_POST);
    }

    if (isset($_GET) && is_array($_GET)) {
        foreach ([
            'id', 'name', 'nome', 'shift', 'turno', 'image', 'imagem',
            'area', 'area_id', 'line', 'linha', 'role', 'funcao',
        ] as $key) {
            if (isset($_GET[$key]) && !isset($body[$key])) {
                $body[$key] = $_GET[$key];
            }
        }
    }

    return $body;
}

/**
 * Create an operator upstream.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $body
 * @return void
 */
function operadores_create(array $cfg, array $body): void
{
    $row = operadores_normalize_row($body, $cfg);
    if ($row === null) {
        json_error('Operator name is required.', 422);
        return;
    }
    if ($row['shift'] === '') {
        json_error('A valid shift is required (4T, 1T or 2T).', 422);
        return;
    }
    if ($row['area'] === '') {
        json_error('An area is required.', 422);
        return;
    }

    $payload = [
        'name'  => $row['name'],
        'shift' => $row['shift'],
        'area'  => operadores_area_value($row['area']),
    ];

    // Keep the image exactly as submitted (URL, data URI or base64) instead
    // of the display-normalised value.
    $rawImage = operadores_pick($body, [
        'image', 'imagem', 'photo', 'foto', 'picture', 'avatar', 'img',
        'image_url', 'photo_url', 'foto_url', 'image_path',
    ]);
    $image = is_scalar($rawImage) ? trim((string) $rawImage) : $row['image'];
    if ($image !== '') {
        if (strlen($image) > 4 * 1024 * 1024) {
            json_error('Image is too large.', 413);
        }
        $payload['image'] = $image;
    }
    $path = trim((string) ($cfg['create_path'] ?? ''));
    if ($path === '') {
        $path = (string) ($cfg['path'] ?? 'operators');
    }
    $url = operadores_api_url($cfg, $path);
    $method = strtoupper((string) ($cfg['create_method'] ?? 'POST'));

    $res = operadores_upstream_write($cfg, $method, $url, $payload);
    if (empty($res['ok'])) {
        json_error(operadores_upstream_error($res, 'create'), operadores_upstream_status($res));
    }

    operadores_bump_cache_version($cfg);

    // Prefer the row returned by the upstream (it usually carries the new id),
    // but keep the submitted values for any field it omits.
    $created = $row;
    if (isset($res['json']) && is_array($res['json'])) {
        $upstreamRow = operadores_normalize_row($res['json'], $cfg);
        if ($upstreamRow !== null) {
            foreach ($upstreamRow as $key => $value) {
                if ($value === '' && isset($row[$key]) && $row[$key] !== '') {
                    $upstreamRow[$key] = $row[$key];
                }
            }
            $created = $upstreamRow;
        } elseif (isset($res['json']['id']) && is_scalar($res['json']['id'])) {
            $created['id'] = trim((string) $res['json']['id']);
        }
    }

    json_response([
        'ok'       => true,
        'operator' => $created,
        'upstream' => operadores_upstream_summary($res),
    ], 201);
}

/**
 * Delete an operator upstream by id.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $body
 * @return void
 */
function operadores_delete(array $cfg, array $body): void
{
    $id = '';
    foreach (['id', 'employee_id', 'employee_number', 'operator_id', 'ssno'] as $key) {
        if (isset($body[$key]) && is_scalar($body[$key])) {
            $id = trim((string) $body[$key]);
            break;
        }
    }
    if ($id === '') {
        json_error('Missing operator id.', 422);
    }

    $area = '';
    foreach (['area', 'area_id'] as $key) {
        if (isset($body[$key]) && is_scalar($body[$key])) {
            $area = trim((string) $body[$key]);
            break;
        }
    }

    $path = trim((string) ($cfg['delete_path'] ?? ''));
    if ($path === '') {
        $path = rtrim((string) ($cfg['path'] ?? 'operators'), '/') . '/{id}';
    }
    $hasIdPlaceholder = strpos($path, '{id}') !== false;
    $hasAreaPlaceholder = strpos($path, '{area}') !== false;
    if ($hasAreaPlaceholder && $area === '') {
        json_error('Missing operator area.', 422);
    }
    $path = str_replace('{id}', rawurlencode($id), $path);
    $path = str_replace('{area}', rawurlencode($area), $path);
    $url = operadores_api_url($cfg, $path);
    $method = strtoupper((string) ($cfg['delete_method'] ?? 'DELETE'));

    $query = [];
    $payload = null;
    if ($method === 'DELETE' || $method === 'GET') {
        if (!$hasIdPlaceholder) {
            $query['id'] = $id;
        }
        if (!$hasAreaPlaceholder && $area !== '') {
            $query['area'] = $area;
        }
    } else {
        // Action-style APIs (e.g. POST operators/delete) get the ids in JSON.
        $payload = ['id' => $id];
        if ($area !== '') {
            $payload['area'] = operadores_area_value($area);
        }
    }

    $res = operadores_upstream_write($cfg, $method, $url, $payload, $query);
    if (empty($res['ok'])) {
        json_error(operadores_upstream_error($res, 'delete'), operadores_upstream_status($res));
    }

    operadores_bump_cache_version($cfg);

    json_response([
        'ok'       => true,
        'id'       => $id,
        'area'     => $area,
        'upstream' => operadores_upstream_summary($res),
    ]);
}

/**
 * Replace the photo of an existing operator.
 *
 * @param array<string,mixed> $cfg
 * @param array<string,mixed> $body
 * @return void
 */
function operadores_update_image(array $cfg, array $body): void
{
    $id = '';
    foreach (['id', 'employee_id', 'employee_number', 'operator_id', 'ssno'] as $key) {
        if (isset($body[$key]) && is_scalar($body[$key])) {
            $id = trim((string) $body[$key]);
            break;
        }
    }
    if ($id === '') {
        json_error('Missing operator id.', 422);
    }

    $rawImage = operadores_pick($body, [
        'image', 'imagem', 'photo', 'foto', 'picture', 'avatar', 'img',
        'image_url', 'photo_url', 'foto_url', 'image_path',
    ]);
    $image = is_scalar($rawImage) ? trim((string) $rawImage) : '';
    if ($image === '') {
        json_error('An image is required.', 422);
    }
    if (strlen($image) > 4 * 1024 * 1024) {
        json_error('Image is too large.', 413);
    }

    $area = '';
    foreach (['area', 'area_id'] as $key) {
        if (isset($body[$key]) && is_scalar($body[$key])) {
            $area = trim((string) $body[$key]);
            break;
        }
    }

    $path = trim((string) ($cfg['update_path'] ?? ''));
    if ($path === '') {
        $path = rtrim((string) ($cfg['path'] ?? 'operators'), '/') . '/{id}';
    }
    $hasIdPlaceholder = strpos($path, '{id}') !== false;
    $hasAreaPlaceholder = strpos($path, '{area}') !== false;
    if ($hasAreaPlaceholder && $area === '') {
        json_error('Missing operator area.', 422);
    }
    $path = str_replace('{id}', rawurlencode($id), $path);
    $path = str_replace('{area}', rawurlencode($area), $path);
    $url = operadores_api_url($cfg, $path);
    $method = strtoupper((string) ($cfg['update_method'] ?? 'PUT'));

    $query = [];
    $payload = ['image' => $image];
    if ($method === 'GET' || $method === 'DELETE') {
        if (!$hasIdPlaceholder) {
            $query['id'] = $id;
        }
    } else {
        if (!$hasIdPlaceholder) {
            $payload['id'] = $id;
        }
    }

    $res = operadores_upstream_write($cfg, $method, $url, $payload, $query);
    if (empty($res['ok'])) {
        json_error(operadores_upstream_error($res, 'update'), operadores_upstream_status($res));
    }

    operadores_bump_cache_version($cfg);

    json_response([
        'ok'       => true,
        'id'       => $id,
        'area'     => $area,
        'image'    => $image,
        'upstream' => operadores_upstream_summary($res),
    ]);
}

/** Numeric area ids stay numbers in the upstream JSON. */
function operadores_area_value($area)
{
    if (!is_scalar($area)) {
        return '';
    }
    $value = trim((string) $area);
    return ($value !== '' && ctype_digit($value)) ? (int) $value : $value;
}

/** Build an upstream URL from a configured path. */
function operadores_api_url(array $cfg, string $path): string
{
    return rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/' . ltrim($path, '/');
}

/**
 * @param array<string,mixed> $cfg
 * @param string $method
 * @param string $url
 * @param mixed $payload
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function operadores_upstream_write(array $cfg, string $method, string $url, $payload = null, array $query = []): array
{
    $http = new Operators\Http\HttpClient(
        (int) ($cfg['timeout'] ?? 8),
        (int) ($cfg['connect_timeout'] ?? 3)
    );

    return $http->sendJson(
        $method,
        $url,
        $payload,
        $query,
        max(1, (int) ($cfg['retries'] ?? 1))
    );
}

/** Human-readable upstream write failure. */
function operadores_upstream_error(array $res, string $action): string
{
    $status = isset($res['status']) ? (int) $res['status'] : 0;
    $error = isset($res['error']) && $res['error'] !== null ? (string) $res['error'] : '';
    $message = 'Operators API ' . $action . ' failed';
    if ($status > 0) {
        $message .= ' (HTTP ' . $status . ')';
    }
    if ($error !== '') {
        $message .= ': ' . $error;
    }
    return $message;
}

/** Upstream 4xx means the request was rejected; 5xx/transport is a bad gateway. */
function operadores_upstream_status(array $res): int
{
    $status = isset($res['status']) ? (int) $res['status'] : 0;
    return ($status >= 400 && $status < 500) ? 422 : 502;
}

/**
 * @param array<string,mixed> $res
 * @return array<string,mixed>
 */
function operadores_upstream_summary(array $res): array
{
    return [
        'status'    => isset($res['status']) ? $res['status'] : null,
        'ok'        => !empty($res['ok']),
        'error'     => isset($res['error']) ? $res['error'] : null,
        'took_ms'   => isset($res['ms']) ? $res['ms'] : null,
        'transport' => isset($res['transport']) ? $res['transport'] : null,
    ];
}

/**
 * Cache key version. Bumped after a write so the next list request (and the
 * board's next poll) does not serve a pre-write cached list.
 */
function operadores_cache_version(array $cfg): int
{
    $dir = rtrim((string) ($cfg['cache_dir'] ?? ''), '/\\');
    if ($dir === '') {
        return 1;
    }
    $file = $dir . DIRECTORY_SEPARATOR . 'operadores.version';
    if (!is_file($file)) {
        return 1;
    }
    $version = (int) trim((string) @file_get_contents($file));
    return $version > 0 ? $version : 1;
}

/** Bump operadores_cache_version() after a successful write. */
function operadores_bump_cache_version(array $cfg): void
{
    $dir = rtrim((string) ($cfg['cache_dir'] ?? ''), '/\\');
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $file = $dir . DIRECTORY_SEPARATOR . 'operadores.version';
    $version = operadores_cache_version($cfg) + 1;
    @file_put_contents($file, (string) $version, LOCK_EX);
}

