<?php
/**
 * Operator management (add / remove).
 *
 * Writes go through api/operadores.php (POST to create, DELETE to remove); the
 * upstream methods and paths are configured under `operadores_api`.
 *
 * Access requires a domain login (lib/auth.php + login.php).
 */

require __DIR__ . '/lib/auth.php';

$authUser = auth_require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
    <title>Operadores - Gestão</title>

    <link rel="stylesheet" type="text/css" href="libraries/bootstrap/bootstrap.min.css"/>
    <link rel="stylesheet" type="text/css" href="assets/app.css?v=<?php echo $assetVer('assets/app.css'); ?>"/>
    <link rel="stylesheet" type="text/css" href="assets/operadores-admin.css?v=<?php echo $assetVer('assets/operadores-admin.css'); ?>"/>
</head>
<body>

<div class="proto-navbar">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <div class="brand">Operadores <span class="text-muted">/ gestão</span></div>
            <div class="subtitle">Adicionar e remover operadores</div>
        </div>
        <div class="d-flex align-items-center flex-wrap">
            <span class="admin-user"><?php echo htmlspecialchars($authUser, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="btn btn-sm btn-outline-secondary mr-2" href="logout.php">Sair</a>
            <a class="btn btn-sm btn-outline-secondary mr-3" href="operadores.php">Ver quadro</a>
            <img alt="logo" src="logo.png" height="34">
        </div>
    </div>
</div>

<div class="container-fluid">

    <div id="adminAlert" class="alert" style="display:none;"></div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Adicionar operador</div>
                <div class="card-body">
                    <form id="operatorForm" autocomplete="off">
                        <div class="form-group">
                            <label for="opName">Nome <span class="text-danger">*</span></label>
                            <input type="text" id="opName" class="form-control" maxlength="120" required>
                        </div>

                        <div class="form-group">
                            <label for="opShift">Turno <span class="text-danger">*</span></label>
                            <select id="opShift" class="form-control" required>
                                <option value="4T">4º Turno</option>
                                <option value="1T" selected>1º Turno</option>
                                <option value="2T">2º Turno</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="opArea">Área <span class="text-danger">*</span></label>
                            <select id="opArea" class="form-control" required>
                                <option value="">A carregar áreas...</option>
                            </select>
                            <div id="areaError" class="text-danger small mt-1" style="display:none;"></div>
                        </div>

                        <div class="form-group">
                            <label for="opImageFile">Fotografia (opcional)</label>
                            <input type="file" id="opImageFile" class="form-control-file" accept="image/*">
                            <div class="admin-photo-preview" id="photoPreview" style="display:none;">
                                <img alt="Pré-visualização" id="photoPreviewImg">
                            </div>
                            <small class="form-text text-muted">Máximo 2 MB</small>
                        </div>

                        <button type="submit" id="opSubmit" class="btn btn-success btn-block">Adicionar operador</button>
                        <div id="opFormError" class="text-danger small mt-2" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Operadores</span>
                    <span class="admin-count" id="adminCount"></span>
                </div>
                <div class="card-body">
                    <div class="form-row align-items-end admin-filters">
                        <div class="form-group col-md-6">
                            <label for="adminSearch">Pesquisar</label>
                            <input type="search" id="adminSearch" class="form-control"
                                   placeholder="Nome ou área" autocomplete="off">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="adminShift">Turno</label>
                            <select id="adminShift" class="form-control">
                                <option value="all">Todos</option>
                                <option value="4T">4º Turno</option>
                                <option value="1T">1º Turno</option>
                                <option value="2T">2º Turno</option>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <button type="button" id="adminRefresh" class="btn btn-outline-secondary btn-block">Atualizar</button>
                        </div>
                    </div>

                    <div id="adminError" class="alert alert-danger" style="display:none;"></div>

                    <div class="admin-photo-hint text-muted small mb-2">
                        Arraste uma imagem para uma linha para substituir a fotografia, ou use <strong>Alterar foto</strong>.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm admin-table mb-0">
                            <thead>
                            <tr>
                                <th scope="col"></th>
                                <th scope="col">Nome</th>
                                <th scope="col">Turno</th>
                                <th scope="col">Área</th>
                                <th scope="col" class="text-right">Ações</th>
                            </tr>
                            </thead>
                            <tbody id="adminOperators">
                            <tr>
                                <td colspan="5" class="admin-empty">A carregar operadores...</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="adminEmpty" class="admin-empty" style="display:none;">
                        Sem operadores para mostrar.
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<input type="file" id="photoFileInput" accept="image/*" style="display:none;">

<script>
    window.OperadoresAdminConfig = {
        apiBase: 'api/',
        csrfToken: <?php echo json_encode(auth_csrf_token(), JSON_UNESCAPED_UNICODE); ?>
    };
</script>
<script type="text/javascript" src="assets/operadores-admin.js?v=<?php echo $assetVer('assets/operadores-admin.js'); ?>"></script>

</body>
</html>

