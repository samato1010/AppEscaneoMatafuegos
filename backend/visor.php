<?php
/**
 * Visor de Matafuegos Escaneados - Belga / HST SRL
 *
 * Muestra los registros de extintores con datos sincronizados
 * desde el sistema AGC (dghpsh.agcontrol.gob.ar).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sincronizar.php';

$mensaje = '';
$tipoMensaje = '';

// Handler del boton de sincronizacion (POST form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sincronizar'])) {
    $resultado = sincronizarPendientes();
    if ($resultado['ok'] > 0) {
        $mensaje = "Se sincronizaron {$resultado['ok']} registros correctamente.";
        if ($resultado['fail'] > 0) {
            $mensaje .= " ({$resultado['fail']} fallidos)";
        }
        $tipoMensaje = 'exito';
    } elseif ($resultado['total'] === 0) {
        $mensaje = "No hay registros pendientes para sincronizar.";
        $tipoMensaje = 'info';
    } else {
        $mensaje = "No se pudo sincronizar ninguno de los {$resultado['total']} registros.";
        $tipoMensaje = 'error';
    }
}

// Obtener contadores
$db = getDB();
$contCargados   = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'cargado'")->fetchColumn();
$contPendientes = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'pendiente'")->fetchColumn();
$contError      = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'error'")->fetchColumn();
$contTotal      = $db->query("SELECT COUNT(*) FROM extintores")->fetchColumn();

// Filtro por estado
$filtroEstado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtrosValidos = ['pendiente', 'cargado', 'error'];

// Paginacion
$porPagina = 20;
$pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($pagina - 1) * $porPagina;

// Consultar registros
$where = '';
$params = [];
if (in_array($filtroEstado, $filtrosValidos)) {
    $where = "WHERE estado = :estado";
    $params[':estado'] = $filtroEstado;
}

$countQuery = "SELECT COUNT(*) FROM extintores $where";
$stmtCount = $db->prepare($countQuery);
$stmtCount->execute($params);
$totalFiltrado = $stmtCount->fetchColumn();
$totalPaginas = max(1, ceil($totalFiltrado / $porPagina));

$query = "SELECT * FROM extintores $where ORDER BY fecha_escaneo DESC LIMIT $porPagina OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute($params);
$registros = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor de Matafuegos - Belga</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            padding: 20px;
        }

        .container { max-width: 1400px; margin: 0 auto; }

        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 { font-size: 22px; font-weight: 700; }
        .header .subtitle { font-size: 13px; color: #a0a0b0; margin-top: 4px; }

        .stats { display: flex; gap: 10px; flex-wrap: wrap; }

        .stat {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .stat.cargado  { background: #d4edda; color: #155724; }
        .stat.cargado .dot  { background: #28a745; }
        .stat.pendiente { background: #fff3cd; color: #856404; }
        .stat.pendiente .dot { background: #ffc107; }
        .stat.error    { background: #f8d7da; color: #721c24; }
        .stat.error .dot    { background: #dc3545; }
        .stat.total    { background: #d1ecf1; color: #0c5460; }
        .stat.total .dot    { background: #17a2b8; }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filtro-grupo { display: flex; align-items: center; gap: 8px; }
        .filtro-grupo label { font-size: 14px; font-weight: 600; color: #555; }

        .filtro-grupo select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .btn-sync {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-sync:hover { background-color: #0056b3; transform: translateY(-1px); }
        .btn-sync:disabled { background-color: #6c757d; cursor: not-allowed; transform: none; }
        .btn-sync.loading .spinner { display: inline-block; animation: spin 1s linear infinite; }
        .btn-sync .spinner { display: none; }
        .btn-sync.loading .btn-text { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .mensaje {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 500;
        }
        .mensaje.exito { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .mensaje.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .mensaje.info  { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        .tabla-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: #f8f9fa;
            padding: 12px 10px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }

        td {
            padding: 10px;
            font-size: 13px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        tr:hover { background-color: #f8f9fa; }

        td.url-cell {
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        td.url-cell a { color: #007bff; text-decoration: none; font-size: 12px; }
        td.url-cell a:hover { text-decoration: underline; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge.pendiente { background: #fff3cd; color: #856404; }
        .badge.cargado   { background: #d4edda; color: #155724; }
        .badge.error     { background: #f8d7da; color: #721c24; }

        .paginacion {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            padding: 15px;
        }

        .paginacion a, .paginacion span {
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            font-weight: 600;
        }

        .paginacion a { background: #e9ecef; color: #495057; }
        .paginacion a:hover { background: #007bff; color: white; }
        .paginacion span.actual { background: #007bff; color: white; }

        .sin-datos { text-align: center; padding: 40px; color: #888; font-size: 16px; }

        @media (max-width: 900px) {
            body { padding: 10px; }
            .header { flex-direction: column; align-items: flex-start; }
            table { font-size: 11px; }
            th, td { padding: 6px; }
            .tabla-container { overflow-x: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Visor de Matafuegos Escaneados</h1>
                <div class="subtitle">HST SRL - Sistema Belga</div>
            </div>
            <div class="stats">
                <div class="stat cargado"><span class="dot"></span> Cargados: <?= $contCargados ?></div>
                <div class="stat pendiente"><span class="dot"></span> Pendientes: <?= $contPendientes ?></div>
                <div class="stat error"><span class="dot"></span> Error: <?= $contError ?></div>
                <div class="stat total"><span class="dot"></span> Total: <?= $contTotal ?></div>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="mensaje <?= $tipoMensaje ?>" id="mensaje"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <div class="filtro-grupo">
                <label for="filtroEstado">Filtrar:</label>
                <select id="filtroEstado" onchange="filtrar(this.value)">
                    <option value="" <?= $filtroEstado === '' ? 'selected' : '' ?>>Todos (<?= $contTotal ?>)</option>
                    <option value="cargado" <?= $filtroEstado === 'cargado' ? 'selected' : '' ?>>Cargados (<?= $contCargados ?>)</option>
                    <option value="pendiente" <?= $filtroEstado === 'pendiente' ? 'selected' : '' ?>>Pendientes (<?= $contPendientes ?>)</option>
                    <option value="error" <?= $filtroEstado === 'error' ? 'selected' : '' ?>>Error (<?= $contError ?>)</option>
                </select>
            </div>

            <button class="btn-sync" id="btnSync" onclick="sincronizar()" <?= ($contPendientes + $contError) == 0 ? 'disabled' : '' ?>>
                <span class="spinner">&#8635;</span>
                <span class="btn-text">Sincronizar Pendientes (<?= $contPendientes + $contError ?>)</span>
            </button>
        </div>

        <div class="tabla-container">
            <?php if (empty($registros)): ?>
                <div class="sin-datos">No hay registros <?= $filtroEstado ? "con estado \"$filtroEstado\"" : '' ?></div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>URL</th>
                            <th>Estado</th>
                            <th>Domicilio</th>
                            <th>Fabricante</th>
                            <th>Recargadora</th>
                            <th>Agente</th>
                            <th>Capacidad</th>
                            <th>Mantenimiento</th>
                            <th>Venc. Mant.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $reg): ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($reg['fecha_escaneo'])) ?></td>
                                <td class="url-cell">
                                    <a href="<?= htmlspecialchars($reg['url']) ?>" target="_blank" title="<?= htmlspecialchars($reg['url']) ?>">Ver en AGC</a>
                                </td>
                                <td><span class="badge <?= htmlspecialchars($reg['estado']) ?>"><?= htmlspecialchars($reg['estado']) ?></span></td>
                                <td><?= htmlspecialchars($reg['domicilio'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['fabricante'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['recargadora'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['agente_extintor'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['capacidad'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['fecha_mantenimiento'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($reg['fecha_venc_mantenimiento'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPaginas > 1): ?>
                    <div class="paginacion">
                        <?php
                        $queryBase = $filtroEstado ? "estado=$filtroEstado&" : '';
                        if ($pagina > 1): ?>
                            <a href="?<?= $queryBase ?>p=<?= $pagina - 1 ?>">&laquo; Anterior</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): ?>
                            <?php if ($i === $pagina): ?>
                                <span class="actual"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?<?= $queryBase ?>p=<?= $i ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagina < $totalPaginas): ?>
                            <a href="?<?= $queryBase ?>p=<?= $pagina + 1 ?>">Siguiente &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filtrar(estado) {
            window.location.href = estado ? '?estado=' + estado : '?';
        }

        async function sincronizar() {
            const btn = document.getElementById('btnSync');
            btn.classList.add('loading');
            btn.disabled = true;

            try {
                const response = await fetch('api_sync.php');
                const data = await response.json();

                let msg = '';
                if (data.ok > 0) {
                    msg = 'Sincronizados: ' + data.ok;
                    if (data.fail > 0) msg += ' | Fallidos: ' + data.fail;
                } else if (data.total === 0) {
                    msg = 'No hay registros pendientes';
                } else {
                    msg = 'No se pudo sincronizar (' + data.fail + ' fallidos)';
                }

                alert(msg);
                window.location.reload();
            } catch (e) {
                alert('Error de conexion al sincronizar');
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        }

        const msgEl = document.getElementById('mensaje');
        if (msgEl) {
            setTimeout(function() {
                msgEl.style.transition = 'opacity 0.5s';
                msgEl.style.opacity = '0';
                setTimeout(function() { msgEl.remove(); }, 500);
            }, 5000);
        }
    </script>
</body>
</html>
