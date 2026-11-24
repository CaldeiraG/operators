<?php
/**
 * Operator board (Operadores / turnos).
 *
 * Reads api/operadores.php and groups the people by plant shift. The board
 * follows the plant clock, highlights the shift in progress and refreshes by
 * itself as soon as a new shift starts.
 *
 * Optional query params:
 *   ?line_id=52          filter the operators endpoint by line
 *   ?shift=1T            start on a single shift
 *   ?display=1           signage/kiosk layout (no filters, forced auto-refresh)
 */

$assetVer = function ($rel) {
    $path = __DIR__ . '/' . $rel;
    return is_file($path) ? (string) filemtime($path) : '1';
};
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operadores - Turnos</title>

    <link rel="stylesheet" type="text/css" href="libraries/bootstrap/bootstrap.min.css"/>
    <link rel="stylesheet" type="text/css" href="assets/app.css?v=<?php echo $assetVer('assets/app.css'); ?>"/>
    <link rel="stylesheet" type="text/css" href="assets/operadores.css?v=<?php echo $assetVer('assets/operadores.css'); ?>"/>

    <script>
    (function () {
        var q = (window.location.search || '').replace(/^\?/, '');
        var p = {};
        if (q) {
            q.split('&').forEach(function (pair) {
                var i = pair.indexOf('=');
                var k = i < 0 ? pair : pair.slice(0, i);
                var v = i < 0 ? '' : pair.slice(i + 1);
                try {
                    p[decodeURIComponent(k)] = decodeURIComponent(v.replace(/\+/g, ' '));
                } catch (e) {
                    p[k] = v;
                }
            });
        }
        var signage = p.display === '1' || p.display === 'signage' || p.display === 'kiosk'
            || p.signage === '1' || p.kiosk === '1';
        if (signage) {
            // Hide the filters from first paint (no flash on slow TV players).
            document.documentElement.className += ' op-signage-mode';
        }
    })();
    </script>
</head>
<body>

<!-- <div class="proto-navbar">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <div class="brand">Operadores <span class="text-muted">/ turnos</span></div>
            <div class="subtitle">Quadro de equipa por turno</div>
        </div>
        <div class="d-flex align-items-center">
            <a class="btn btn-sm btn-outline-secondary mr-3 op-nav-link" href="index.php">Produção</a>
            <img alt="logo" src="logo.png" height="34">
        </div>
    </div>
</div> -->

<div class="container-fluid">

   <!--  <div class="card" id="filtersCard">
        <div class="card-header">Filtros</div>
        <div class="card-body">
            <form id="opFilters" class="form-row align-items-end" onsubmit="return false;">
                <div class="form-group col-md-5">
                    <label for="opSearch">Pesquisar</label>
                    <input type="search" id="opSearch" class="form-control" autocomplete="off"
                           placeholder="Nome, linha ou função">
                </div>
                <div class="form-group col-md-4 d-flex align-items-end">
                    <button type="button" id="opRefresh" class="btn btn-success mr-3">Atualizar</button>
                    <div class="custom-control custom-checkbox mr-3">
                        <input type="checkbox" class="custom-control-input" id="opAuto" checked>
                        <label class="custom-control-label" for="opAuto">Auto</label>
                    </div>
                    <div id="opWaiting" class="text-muted small" style="display:none;">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                        A atualizar...
                    </div>
                </div>
                <div class="form-group col-md-3 text-md-right">
                    <div class="op-updated" id="opUpdated">A carregar...</div>
                    <div class="op-source" id="opSource"></div>
                </div>
            </form>
        </div>
    </div> -->

    <div id="opDemo" class="alert alert-warning" style="display:none;">
        A mostrar dados de demonstração. A API de operadores não respondeu.
    </div>

    <div id="opError" class="alert alert-danger" style="display:none;"></div>

    <div id="opNotice" class="alert alert-success op-notice" style="display:none;"></div>

    <div class="live-panel" id="livePanel" data-shift="">
        <div class="live-main">
            <span class="live-shift" id="liveShift">A carregar...</span>
            <!-- <div class="live-shift-row">
                <span class="live-shift" id="liveShift">--</span>
                <span class="live-range" id="liveRange">A carregar...</span>
                <span class="live-current-badge" id="liveCurrentBadge" style="display:none;">Em curso</span>
            </div> -->
            <span class="live-date" id="liveDate"></span>
        </div>
        <div class="live-timer">
            <div class="live-clock" id="liveClock">--:--</div>
            <!-- <div class="live-next">
                Próximo turno <strong id="liveNextShift">--</strong>
                em <span id="liveCountdown">--:--:--</span>
            </div> -->
            <!-- <div class="live-progress" aria-hidden="true">
                <div class="live-progress-bar" id="liveProgressBar"></div>
            </div> -->
        </div>
    </div>

<!--     <div class="op-chips" id="shiftSummary"></div> -->

    <div id="operatorsWrap">
        <div class="op-loading">
            <span class="spinner-border spinner-border-sm" role="status"></span>
            A carregar operadores...
        </div>
    </div>

</div>

<script>
    window.OperadoresConfig = {
        apiBase: 'api/',
        pollSeconds: 120
    };
</script>
<script type="text/javascript" src="assets/operadores.js?v=<?php echo $assetVer('assets/operadores.js'); ?>"></script>

</body>
</html>

