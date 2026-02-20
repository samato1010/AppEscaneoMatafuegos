<?php
/**
 * Visor de Matafuegos Escaneados - Belga / HST SRL
 * v2.0 - Con buscador, detalle expandible, exportar CSV, alertas de vencimiento
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sincronizar.php';

// === EXPORTAR CSV ===
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM extintores ORDER BY fecha_escaneo DESC");
    $todos = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="matafuegos_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM para Excel
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Fecha Escaneo', 'Estado', 'Domicilio', 'Fabricante', 'Recargadora',
        'Agente Extintor', 'Capacidad', 'Fecha Mantenimiento', 'Venc. Mantenimiento',
        'Fecha Fabricacion', 'Venc. Vida Util', 'Venc. PH', 'Nro Tarjeta', 'Nro Extintor', 'Uso', 'URL']);

    foreach ($todos as $r) {
        fputcsv($out, [
            $r['id'], $r['fecha_escaneo'], $r['estado'], $r['domicilio'], $r['fabricante'],
            $r['recargadora'], $r['agente_extintor'], $r['capacidad'], $r['fecha_mantenimiento'],
            $r['fecha_venc_mantenimiento'], $r['fecha_fabricacion'] ?? '', $r['venc_vida_util'] ?? '',
            $r['venc_ph'] ?? '', $r['nro_tarjeta'] ?? '', $r['nro_extintor'] ?? '', $r['uso'] ?? '', $r['url']
        ]);
    }
    fclose($out);
    exit;
}

// === API HISTORIAL DE ESCANEOS POR EXTINTOR ===
if (isset($_GET['api']) && $_GET['api'] === 'historial' && isset($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $db = getDB();
    $extintorId = (int)$_GET['id'];

    // Datos del extintor
    $stmtExt = $db->prepare("SELECT id, url, domicilio, nro_extintor, nro_tarjeta, fecha_escaneo FROM extintores WHERE id = :id");
    $stmtExt->execute([':id' => $extintorId]);
    $extintor = $stmtExt->fetch(PDO::FETCH_ASSOC);

    if (!$extintor) {
        echo json_encode(['error' => 'Extintor no encontrado']);
        exit;
    }

    // Historial de escaneos
    $stmtHist = $db->prepare(
        "SELECT id, fecha_escaneo, origen
         FROM historial_escaneos
         WHERE extintor_id = :eid
         ORDER BY fecha_escaneo DESC"
    );
    $stmtHist->execute([':eid' => $extintorId]);
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'extintor' => $extintor,
        'historial' => $historial,
        'total_escaneos' => count($historial)
    ]);
    exit;
}

// === DATOS API PARA AUTO-REFRESH ===
if (isset($_GET['api']) && $_GET['api'] === 'datos') {
    header('Content-Type: application/json; charset=utf-8');
    $db = getDB();

    $busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
    $filtroEstado = isset($_GET['estado']) ? $_GET['estado'] : '';
    $filtrosValidos = ['pendiente', 'cargado', 'error'];
    $porPagina = 20;
    $pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($pagina - 1) * $porPagina;

    $where = [];
    $params = [];

    if (in_array($filtroEstado, $filtrosValidos)) {
        $where[] = "e.estado = :estado";
        $params[':estado'] = $filtroEstado;
    }

    if ($busqueda !== '') {
        $where[] = "(e.domicilio LIKE :q1 OR e.fabricante LIKE :q2 OR e.recargadora LIKE :q3 OR e.nro_extintor LIKE :q4 OR e.nro_tarjeta LIKE :q5 OR e.agente_extintor LIKE :q6)";
        $params[':q1'] = "%$busqueda%";
        $params[':q2'] = "%$busqueda%";
        $params[':q3'] = "%$busqueda%";
        $params[':q4'] = "%$busqueda%";
        $params[':q5'] = "%$busqueda%";
        $params[':q6'] = "%$busqueda%";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Contadores globales (sin filtro)
    $contCargados   = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'cargado'")->fetchColumn();
    $contPendientes = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'pendiente'")->fetchColumn();
    $contError      = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'error'")->fetchColumn();
    $contTotal      = $db->query("SELECT COUNT(*) FROM extintores")->fetchColumn();

    // Total filtrado (usa alias 'e' para coincidir con WHERE)
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM extintores e $whereSQL");
    $stmtCount->execute($params);
    $totalFiltrado = $stmtCount->fetchColumn();
    $totalPaginas = max(1, ceil($totalFiltrado / $porPagina));

    // Registros con conteo de escaneos del historial
    $stmt = $db->prepare(
        "SELECT e.*, COALESCE(h.cnt, 0) AS total_escaneos
         FROM extintores e
         LEFT JOIN (SELECT extintor_id, COUNT(*) AS cnt FROM historial_escaneos GROUP BY extintor_id) h
           ON h.extintor_id = e.id
         $whereSQL
         ORDER BY e.fecha_escaneo DESC
         LIMIT $porPagina OFFSET $offset"
    );
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'contadores' => [
            'cargados' => (int)$contCargados,
            'pendientes' => (int)$contPendientes,
            'error' => (int)$contError,
            'total' => (int)$contTotal,
        ],
        'registros' => $registros,
        'totalFiltrado' => (int)$totalFiltrado,
        'totalPaginas' => (int)$totalPaginas,
        'paginaActual' => (int)$pagina,
    ]);
    exit;
}

// === RENDER HTML ===
$db = getDB();
$contCargados   = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'cargado'")->fetchColumn();
$contPendientes = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'pendiente'")->fetchColumn();
$contError      = $db->query("SELECT COUNT(*) FROM extintores WHERE estado = 'error'")->fetchColumn();
$contTotal      = $db->query("SELECT COUNT(*) FROM extintores")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor de Matafuegos - Belga</title>
    <style>
        :root {
            --bg: #f0f2f5;
            --card: #ffffff;
            --header-from: #1a1a2e;
            --header-to: #16213e;
            --text: #333;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --green: #22c55e;
            --green-bg: #dcfce7;
            --green-text: #166534;
            --yellow: #f59e0b;
            --yellow-bg: #fef3c7;
            --yellow-text: #92400e;
            --red: #ef4444;
            --red-bg: #fee2e2;
            --red-text: #991b1b;
            --blue: #3b82f6;
            --blue-bg: #dbeafe;
            --blue-text: #1e40af;
            --purple: #8b5cf6;
            --radius: 12px;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-lg: 0 4px 12px rgba(0,0,0,0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 16px;
            line-height: 1.5;
        }

        .container { max-width: 1400px; margin: 0 auto; }

        /* === HEADER === */
        .header {
            background: linear-gradient(135deg, var(--header-from), var(--header-to));
            color: white;
            padding: 24px 28px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header h1 { font-size: 22px; font-weight: 700; }
        .header .subtitle { font-size: 13px; color: #a0aec0; margin-top: 2px; }

        .stats { display: flex; gap: 8px; flex-wrap: wrap; }
        .stat {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            user-select: none;
        }
        .stat:hover { transform: scale(1.05); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .stat.active { outline: 2px solid white; outline-offset: 2px; }
        .stat .dot { width: 8px; height: 8px; border-radius: 50%; }
        .stat.cargado  { background: var(--green-bg); color: var(--green-text); }
        .stat.cargado .dot  { background: var(--green); }
        .stat.pendiente { background: var(--yellow-bg); color: var(--yellow-text); }
        .stat.pendiente .dot { background: var(--yellow); }
        .stat.error    { background: var(--red-bg); color: var(--red-text); }
        .stat.error .dot    { background: var(--red); }
        .stat.total    { background: var(--blue-bg); color: var(--blue-text); }
        .stat.total .dot    { background: var(--blue); }

        /* === TOOLBAR === */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .toolbar-left { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; flex: 1; }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 360px;
            min-width: 200px;
        }
        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-box input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .search-box .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 15px;
            pointer-events: none;
        }

        .toolbar-right { display: flex; gap: 8px; flex-wrap: wrap; }

        .btn {
            border: none;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .btn-sync { background: var(--blue); color: white; }
        .btn-sync:hover { background: #2563eb; }
        .btn-csv { background: var(--green); color: white; }
        .btn-csv:hover { background: #16a34a; }

        .btn .spinner { display: none; animation: spin 0.8s linear infinite; }
        .btn.loading .spinner { display: inline-block; }
        .btn.loading .btn-text { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* === TOAST === */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease;
            max-width: 400px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toast.success { background: var(--green-bg); color: var(--green-text); border-left: 4px solid var(--green); }
        .toast.error { background: var(--red-bg); color: var(--red-text); border-left: 4px solid var(--red); }
        .toast.info { background: var(--blue-bg); color: var(--blue-text); border-left: 4px solid var(--blue); }
        .toast.fadeOut { animation: slideOut 0.3s ease forwards; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { opacity: 1; } to { opacity: 0; transform: translateX(50px); } }

        /* === TABLA (Desktop) === */
        .tabla-container {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td {
            padding: 10px 12px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        tr { cursor: pointer; transition: background 0.15s; }
        tr:hover { background: #f8fafc; }
        tr.expanded { background: #eff6ff; }

        .url-cell a { color: var(--blue); text-decoration: none; font-size: 12px; }
        .url-cell a:hover { text-decoration: underline; }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge.pendiente { background: var(--yellow-bg); color: var(--yellow-text); }
        .badge.cargado   { background: var(--green-bg); color: var(--green-text); }
        .badge.error     { background: var(--red-bg); color: var(--red-text); }

        .venc-ok { color: var(--green-text); font-weight: 600; }
        .venc-pronto { color: var(--yellow-text); font-weight: 700; background: var(--yellow-bg); padding: 2px 8px; border-radius: 8px; }
        .venc-vencido { color: var(--red-text); font-weight: 700; background: var(--red-bg); padding: 2px 8px; border-radius: 8px; }

        /* === DETALLE EXPANDIBLE === */
        .detalle-row td { padding: 0; border-bottom: 2px solid var(--blue); }
        .detalle-content {
            padding: 16px 20px;
            background: #f8fafc;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
        }
        .detalle-item {
            display: flex;
            flex-direction: column;
        }
        .detalle-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }
        .detalle-valor {
            font-size: 14px;
            color: var(--text);
            font-weight: 500;
        }

        /* === CARDS (Mobile) === */
        .cards-container { display: none; }

        .card-extintor {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 10px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .card-extintor:hover { box-shadow: var(--shadow-lg); }

        .card-header {
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            cursor: pointer;
            gap: 10px;
        }
        .card-header-left { flex: 1; }
        .card-domicilio {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }
        .card-meta {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .card-toggle {
            font-size: 18px;
            color: var(--text-muted);
            transition: transform 0.2s;
            flex-shrink: 0;
            margin-top: 4px;
        }
        .card-extintor.open .card-toggle { transform: rotate(180deg); }

        .card-body {
            display: none;
            padding: 0 16px 16px;
            border-top: 1px solid var(--border);
        }
        .card-extintor.open .card-body { display: block; padding-top: 14px; }

        .card-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .card-field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .card-field-value {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
        }

        /* === PAGINACION === */
        .paginacion {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            padding: 14px;
        }
        .paginacion button {
            padding: 7px 13px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid var(--border);
            background: white;
            color: var(--text);
            cursor: pointer;
            transition: all 0.15s;
        }
        .paginacion button:hover { background: var(--blue); color: white; border-color: var(--blue); }
        .paginacion button.active { background: var(--blue); color: white; border-color: var(--blue); }
        .paginacion button:disabled { opacity: 0.4; cursor: not-allowed; }

        .sin-datos { text-align: center; padding: 50px 20px; color: var(--text-muted); font-size: 16px; }
        .sin-datos .icon { font-size: 48px; margin-bottom: 12px; display: block; }

        /* === AUTO REFRESH INDICATOR === */
        .refresh-indicator {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .refresh-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--green);
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* === LUPA HISTORIAL === */
        .btn-historial {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s;
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-historial:hover { background: var(--blue-bg); transform: scale(1.1); }
        .btn-historial .hist-badge {
            font-size: 10px;
            font-weight: 800;
            background: var(--blue);
            color: white;
            border-radius: 10px;
            padding: 1px 5px;
            min-width: 16px;
            text-align: center;
            line-height: 14px;
        }

        /* === MODAL HISTORIAL === */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease;
        }
        .modal-overlay.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal {
            background: white;
            border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 520px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-header .close-btn {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px;
            border-radius: 6px;
            line-height: 1;
        }
        .modal-header .close-btn:hover { background: var(--red-bg); color: var(--red); }

        .modal-body {
            padding: 18px 22px;
            overflow-y: auto;
            max-height: calc(80vh - 80px);
        }

        .modal-extintor-info {
            background: var(--blue-bg);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .modal-extintor-info strong { color: var(--blue-text); }

        .timeline { position: relative; padding-left: 28px; }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .timeline-item:last-child { border-bottom: none; }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -22px;
            top: 16px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--blue);
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--blue);
        }
        .timeline-item:first-child::before { background: var(--green); box-shadow: 0 0 0 2px var(--green); }

        .timeline-fecha {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }
        .timeline-origen {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .timeline-numero {
            font-size: 11px;
            color: var(--blue);
            font-weight: 700;
        }

        .modal-loading {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }

        /* === RESPONSIVE === */
        @media (max-width: 900px) {
            body { padding: 10px; }
            .header { flex-direction: column; align-items: flex-start; padding: 18px 16px; }
            .header h1 { font-size: 20px; }
            .stats { gap: 6px; }
            .stat { font-size: 12px; padding: 5px 10px; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left { flex-direction: column; }
            .search-box { max-width: 100%; }
            .toolbar-right { justify-content: stretch; }
            .toolbar-right .btn { flex: 1; justify-content: center; }

            /* Mobile: cards en vez de tabla */
            .tabla-container { display: none; }
            .cards-container { display: block; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Toast container -->
        <div class="toast-container" id="toastContainer"></div>

        <!-- Header -->
        <div class="header">
            <div>
                <h1>Visor de Matafuegos Escaneados</h1>
                <div class="subtitle">HST SRL - Sistema Belga</div>
            </div>
            <div class="stats" id="statsContainer">
                <div class="stat cargado" onclick="filtrarEstado('cargado')" id="statCargado">
                    <span class="dot"></span> Cargados: <span id="contCargados"><?= $contCargados ?></span>
                </div>
                <div class="stat pendiente" onclick="filtrarEstado('pendiente')" id="statPendiente">
                    <span class="dot"></span> Pendientes: <span id="contPendientes"><?= $contPendientes ?></span>
                </div>
                <div class="stat error" onclick="filtrarEstado('error')" id="statError">
                    <span class="dot"></span> Error: <span id="contError"><?= $contError ?></span>
                </div>
                <div class="stat total" onclick="filtrarEstado('')" id="statTotal">
                    <span class="dot"></span> Total: <span id="contTotal"><?= $contTotal ?></span>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <span class="search-icon">&#128269;</span>
                    <input type="text" id="inputBusqueda" placeholder="Buscar domicilio, fabricante, nro extintor..."
                           autocomplete="off" />
                </div>
                <div class="refresh-indicator">
                    <span class="refresh-dot"></span>
                    Auto-refresh <span id="refreshCountdown">30</span>s
                </div>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-csv" onclick="exportarCSV()">
                    &#128190; Exportar CSV
                </button>
                <button class="btn btn-sync" id="btnSync" onclick="sincronizar()">
                    <span class="spinner">&#8635;</span>
                    <span class="btn-text">&#9889; Sincronizar (<span id="syncCount"><?= $contPendientes + $contError ?></span>)</span>
                </button>
            </div>
        </div>

        <!-- Tabla Desktop -->
        <div class="tabla-container" id="tablaContainer">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;text-align:center" title="Historial de escaneos">Hist.</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Domicilio</th>
                        <th>Fabricante</th>
                        <th>Recargadora</th>
                        <th>Agente</th>
                        <th>Capacidad</th>
                        <th>Vence Mant.</th>
                        <th>Vence en</th>
                    </tr>
                </thead>
                <tbody id="tablaBody">
                </tbody>
            </table>
            <div id="paginacionContainer" class="paginacion"></div>
            <div id="sinDatosDesktop" class="sin-datos" style="display:none">
                <span class="icon">&#128270;</span>
                No se encontraron registros
            </div>
        </div>

        <!-- Cards Mobile -->
        <div class="cards-container" id="cardsContainer">
        </div>
        <div id="paginacionMobile" class="paginacion" style="display:none"></div>
    </div>

    <!-- Modal Historial -->
    <div class="modal-overlay" id="modalHistorial" onclick="cerrarModal(event)">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>&#128269; Historial de Escaneos</h3>
                <button class="close-btn" onclick="cerrarModalHistorial()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="modal-loading">Cargando historial...</div>
            </div>
        </div>
    </div>

    <script>
    // === STATE ===
    let state = {
        filtroEstado: '',
        busqueda: '',
        pagina: 1,
        refreshTimer: null,
        refreshCountdown: 30,
        countdownTimer: null,
    };

    // === INIT ===
    document.addEventListener('DOMContentLoaded', () => {
        cargarDatos();
        iniciarAutoRefresh();

        // Busqueda con debounce
        let debounceTimer;
        document.getElementById('inputBusqueda').addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                state.busqueda = e.target.value.trim();
                state.pagina = 1;
                cargarDatos();
            }, 400);
        });
    });

    // === CARGAR DATOS (AJAX) ===
    async function cargarDatos() {
        const params = new URLSearchParams();
        params.set('api', 'datos');
        if (state.filtroEstado) params.set('estado', state.filtroEstado);
        if (state.busqueda) params.set('q', state.busqueda);
        params.set('p', state.pagina);

        try {
            const resp = await fetch('visor.php?' + params.toString());
            const data = await resp.json();
            renderContadores(data.contadores);
            renderTabla(data.registros);
            renderCards(data.registros);
            renderPaginacion(data.totalPaginas, data.paginaActual, data.totalFiltrado);
            actualizarSyncBtn(data.contadores);
        } catch (e) {
            console.error('Error cargando datos:', e);
        }
    }

    // === RENDER CONTADORES ===
    function renderContadores(c) {
        document.getElementById('contCargados').textContent = c.cargados;
        document.getElementById('contPendientes').textContent = c.pendientes;
        document.getElementById('contError').textContent = c.error;
        document.getElementById('contTotal').textContent = c.total;

        // Highlight activo
        document.querySelectorAll('.stat').forEach(s => s.classList.remove('active'));
        if (state.filtroEstado === 'cargado') document.getElementById('statCargado').classList.add('active');
        else if (state.filtroEstado === 'pendiente') document.getElementById('statPendiente').classList.add('active');
        else if (state.filtroEstado === 'error') document.getElementById('statError').classList.add('active');
        else document.getElementById('statTotal').classList.add('active');
    }

    // === RENDER TABLA (Desktop) ===
    function renderTabla(registros) {
        const tbody = document.getElementById('tablaBody');
        const sinDatos = document.getElementById('sinDatosDesktop');

        if (registros.length === 0) {
            tbody.innerHTML = '';
            sinDatos.style.display = 'block';
            return;
        }
        sinDatos.style.display = 'none';

        let html = '';
        registros.forEach((r, idx) => {
            const vencInfo = calcularVencimiento(r.fecha_venc_mantenimiento);
            const totalEscaneos = parseInt(r.total_escaneos) || 0;
            const badgeHtml = totalEscaneos > 1 ? `<span class='hist-badge'>${totalEscaneos}</span>` : '';
            const lupaHtml = `<button class="btn-historial" onclick="event.stopPropagation(); verHistorial(${r.id})" title="Ver historial de escaneos">&#128269;${badgeHtml}</button>`;
            html += `<tr onclick="toggleDetalle(${idx})">
                <td style="text-align:center">${lupaHtml}</td>
                <td style="white-space:nowrap">${formatFecha(r.fecha_escaneo)}</td>
                <td><span class="badge ${esc(r.estado)}">${esc(r.estado)}</span></td>
                <td>${esc(r.domicilio || '-')}</td>
                <td>${esc(r.fabricante || '-')}</td>
                <td>${esc(r.recargadora || '-')}</td>
                <td>${esc(r.agente_extintor || '-')}</td>
                <td>${esc(r.capacidad || '-')}</td>
                <td>${vencInfo.fechaHtml}</td>
                <td>${vencInfo.diasHtml}</td>
            </tr>`;
            html += `<tr class="detalle-row" id="detalle-${idx}" style="display:none">
                <td colspan="10">
                    <div class="detalle-content">
                        <div class="detalle-item"><span class="detalle-label">URL AGC</span><span class="detalle-valor"><a href="${esc(r.url)}" target="_blank" style="color:var(--blue)">Ver en AGC &#8599;</a></span></div>
                        <div class="detalle-item"><span class="detalle-label">Domicilio</span><span class="detalle-valor">${esc(r.domicilio || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Fabricante</span><span class="detalle-valor">${esc(r.fabricante || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Recargadora</span><span class="detalle-valor">${esc(r.recargadora || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Agente Extintor</span><span class="detalle-valor">${esc(r.agente_extintor || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Capacidad</span><span class="detalle-valor">${esc(r.capacidad || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Fecha Mantenimiento</span><span class="detalle-valor">${esc(r.fecha_mantenimiento || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Venc. Mantenimiento</span><span class="detalle-valor">${vencInfo.fechaHtml}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Fecha Fabricacion</span><span class="detalle-valor">${esc(r.fecha_fabricacion || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Venc. Vida Util</span><span class="detalle-valor">${esc(r.venc_vida_util || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Venc. PH</span><span class="detalle-valor">${esc(r.venc_ph || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Nro. Tarjeta</span><span class="detalle-valor">${esc(r.nro_tarjeta || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Nro. Extintor</span><span class="detalle-valor">${esc(r.nro_extintor || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Uso</span><span class="detalle-valor">${esc(r.uso || '-')}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Fecha Escaneo</span><span class="detalle-valor">${formatFechaFull(r.fecha_escaneo)}</span></div>
                        <div class="detalle-item"><span class="detalle-label">Fecha Sincronizacion</span><span class="detalle-valor">${formatFechaFull(r.fecha_sincronizacion)}</span></div>
                    </div>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    // === RENDER CARDS (Mobile) ===
    function renderCards(registros) {
        const container = document.getElementById('cardsContainer');
        if (registros.length === 0) {
            container.innerHTML = '<div class="sin-datos"><span class="icon">&#128270;</span>No se encontraron registros</div>';
            return;
        }

        let html = '';
        registros.forEach((r, idx) => {
            const vencInfo = calcularVencimiento(r.fecha_venc_mantenimiento);
            html += `<div class="card-extintor" id="card-${idx}">
                <div class="card-header" onclick="toggleCard(${idx})">
                    <div class="card-header-left">
                        <div class="card-domicilio">${esc(r.domicilio || 'Sin domicilio')}</div>
                        <div class="card-meta">
                            <span class="badge ${esc(r.estado)}">${esc(r.estado)}</span>
                            <span>${formatFecha(r.fecha_escaneo)}</span>
                            ${vencInfo.diasHtml ? vencInfo.diasHtml : ''}
                        </div>
                    </div>
                    <span class="card-toggle">&#9660;</span>
                </div>
                <div class="card-body">
                    <div class="card-grid">
                        <div><div class="card-field-label">Fabricante</div><div class="card-field-value">${esc(r.fabricante || '-')}</div></div>
                        <div><div class="card-field-label">Recargadora</div><div class="card-field-value">${esc(r.recargadora || '-')}</div></div>
                        <div><div class="card-field-label">Agente</div><div class="card-field-value">${esc(r.agente_extintor || '-')}</div></div>
                        <div><div class="card-field-label">Capacidad</div><div class="card-field-value">${esc(r.capacidad || '-')}</div></div>
                        <div><div class="card-field-label">Mantenimiento</div><div class="card-field-value">${esc(r.fecha_mantenimiento || '-')}</div></div>
                        <div><div class="card-field-label">Venc. Mant.</div><div class="card-field-value">${vencInfo.fechaHtml}</div></div>
                        <div><div class="card-field-label">Fecha Fabric.</div><div class="card-field-value">${esc(r.fecha_fabricacion || '-')}</div></div>
                        <div><div class="card-field-label">Venc. Vida Util</div><div class="card-field-value">${esc(r.venc_vida_util || '-')}</div></div>
                        <div><div class="card-field-label">Venc. PH</div><div class="card-field-value">${esc(r.venc_ph || '-')}</div></div>
                        <div><div class="card-field-label">Nro. Tarjeta</div><div class="card-field-value">${esc(r.nro_tarjeta || '-')}</div></div>
                        <div><div class="card-field-label">Nro. Extintor</div><div class="card-field-value">${esc(r.nro_extintor || '-')}</div></div>
                        <div><div class="card-field-label">Uso</div><div class="card-field-value">${esc(r.uso || '-')}</div></div>
                    </div>
                    <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
                        <a href="${esc(r.url)}" target="_blank" style="color:var(--blue);font-size:13px;font-weight:600">Ver en AGC &#8599;</a>
                        <button class="btn-historial" onclick="event.stopPropagation(); verHistorial(${r.id})" title="Ver historial">
                            &#128269; Historial ${parseInt(r.total_escaneos) > 1 ? '<span class="hist-badge">' + r.total_escaneos + '</span>' : ''}
                        </button>
                    </div>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    }

    // === RENDER PAGINACION ===
    function renderPaginacion(totalPaginas, paginaActual, totalFiltrado) {
        if (totalPaginas <= 1) {
            document.getElementById('paginacionContainer').innerHTML = '';
            document.getElementById('paginacionMobile').style.display = 'none';
            return;
        }

        let html = `<button onclick="irPagina(${paginaActual - 1})" ${paginaActual <= 1 ? 'disabled' : ''}>&laquo;</button>`;
        for (let i = Math.max(1, paginaActual - 2); i <= Math.min(totalPaginas, paginaActual + 2); i++) {
            html += `<button onclick="irPagina(${i})" class="${i === paginaActual ? 'active' : ''}">${i}</button>`;
        }
        html += `<button onclick="irPagina(${paginaActual + 1})" ${paginaActual >= totalPaginas ? 'disabled' : ''}>&raquo;</button>`;

        document.getElementById('paginacionContainer').innerHTML = html;
        const pagMobile = document.getElementById('paginacionMobile');
        pagMobile.innerHTML = html;
        pagMobile.style.display = 'flex';
    }

    // === HELPERS ===
    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatFecha(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function formatFechaFull(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function calcularVencimiento(fechaVenc) {
        if (!fechaVenc || fechaVenc === '-') {
            return { fechaHtml: '-', diasHtml: '-' };
        }

        // Parsear fecha MM/YYYY o DD/MM/YYYY
        let fechaObj = null;
        const partes = fechaVenc.split('/');
        if (partes.length === 2) {
            // MM/YYYY
            fechaObj = new Date(parseInt(partes[1]), parseInt(partes[0]) - 1, 1);
        } else if (partes.length === 3) {
            // DD/MM/YYYY
            fechaObj = new Date(parseInt(partes[2]), parseInt(partes[1]) - 1, parseInt(partes[0]));
        }

        if (!fechaObj || isNaN(fechaObj)) {
            return { fechaHtml: esc(fechaVenc), diasHtml: '-' };
        }

        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        const diffMs = fechaObj - hoy;
        const diffDias = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

        let fechaClass = 'venc-ok';
        let diasHtml = '';

        if (diffDias < 0) {
            fechaClass = 'venc-vencido';
            diasHtml = `<span class="venc-vencido">Vencido (${Math.abs(diffDias)}d)</span>`;
        } else if (diffDias <= 30) {
            fechaClass = 'venc-pronto';
            diasHtml = `<span class="venc-pronto">${diffDias} dias</span>`;
        } else if (diffDias <= 90) {
            diasHtml = `<span style="color:var(--yellow-text);font-weight:600">${diffDias} dias</span>`;
        } else {
            diasHtml = `<span class="venc-ok">${diffDias} dias</span>`;
        }

        return {
            fechaHtml: `<span class="${fechaClass}">${esc(fechaVenc)}</span>`,
            diasHtml: diasHtml
        };
    }

    // === ACCIONES ===
    function filtrarEstado(estado) {
        state.filtroEstado = (state.filtroEstado === estado) ? '' : estado;
        state.pagina = 1;
        cargarDatos();
        resetAutoRefresh();
    }

    function irPagina(p) {
        state.pagina = p;
        cargarDatos();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function toggleDetalle(idx) {
        const row = document.getElementById('detalle-' + idx);
        const isVisible = row.style.display === 'table-row';
        // Cerrar todos
        document.querySelectorAll('.detalle-row').forEach(r => r.style.display = 'none');
        document.querySelectorAll('tr.expanded').forEach(r => r.classList.remove('expanded'));
        // Toggle
        if (!isVisible) {
            row.style.display = 'table-row';
            row.previousElementSibling.classList.add('expanded');
        }
    }

    function toggleCard(idx) {
        document.getElementById('card-' + idx).classList.toggle('open');
    }

    function actualizarSyncBtn(contadores) {
        const total = contadores.pendientes + contadores.error;
        document.getElementById('syncCount').textContent = total;
        document.getElementById('btnSync').disabled = total === 0;
    }

    // === SINCRONIZAR ===
    async function sincronizar() {
        const btn = document.getElementById('btnSync');
        btn.classList.add('loading');
        btn.disabled = true;

        try {
            const resp = await fetch('api_sync.php');
            const data = await resp.json();

            if (data.ok > 0) {
                showToast(`Sincronizados: ${data.ok} registros`, 'success');
                if (data.fail > 0) showToast(`${data.fail} fallidos`, 'error');
            } else if (data.total === 0) {
                showToast('No hay registros pendientes', 'info');
            } else {
                showToast(`Error: ${data.fail} fallidos`, 'error');
            }

            cargarDatos();
        } catch (e) {
            showToast('Error de conexion al sincronizar', 'error');
        } finally {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    }

    // === EXPORTAR CSV ===
    function exportarCSV() {
        window.location.href = 'visor.php?exportar=csv';
    }

    // === TOAST ===
    function showToast(msg, type) {
        const container = document.getElementById('toastContainer');
        const icons = { success: '&#10003;', error: '&#10007;', info: '&#8505;' };
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${icons[type] || ''}</span> ${esc(msg)}`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('fadeOut');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // === AUTO REFRESH ===
    function iniciarAutoRefresh() {
        state.refreshCountdown = 30;
        actualizarCountdown();
        state.countdownTimer = setInterval(() => {
            state.refreshCountdown--;
            actualizarCountdown();
            if (state.refreshCountdown <= 0) {
                cargarDatos();
                state.refreshCountdown = 30;
            }
        }, 1000);
    }

    function resetAutoRefresh() {
        state.refreshCountdown = 30;
    }

    function actualizarCountdown() {
        const el = document.getElementById('refreshCountdown');
        if (el) el.textContent = state.refreshCountdown;
    }

    // === HISTORIAL DE ESCANEOS ===
    async function verHistorial(extintorId) {
        const modal = document.getElementById('modalHistorial');
        const body = document.getElementById('modalBody');

        modal.classList.add('active');
        body.innerHTML = '<div class="modal-loading">&#8987; Cargando historial...</div>';

        try {
            const resp = await fetch(`visor.php?api=historial&id=${extintorId}`);
            const data = await resp.json();

            if (data.error) {
                body.innerHTML = `<div class="modal-loading">${esc(data.error)}</div>`;
                return;
            }

            let html = '';

            // Info del extintor
            const ext = data.extintor;
            html += `<div class="modal-extintor-info">
                <strong>Extintor #${ext.id}</strong><br>
                ${ext.domicilio ? esc(ext.domicilio) + '<br>' : ''}
                ${ext.nro_extintor ? 'Nro: ' + esc(ext.nro_extintor) + ' | ' : ''}
                ${ext.nro_tarjeta ? 'Tarjeta: ' + esc(ext.nro_tarjeta) : ''}
                <br><strong>${data.total_escaneos} escaneo${data.total_escaneos !== 1 ? 's' : ''} registrado${data.total_escaneos !== 1 ? 's' : ''}</strong>
            </div>`;

            if (data.historial.length === 0) {
                html += '<div style="text-align:center;padding:20px;color:var(--text-muted)">Sin historial de escaneos</div>';
            } else {
                html += '<div class="timeline">';
                data.historial.forEach((h, i) => {
                    const numero = data.total_escaneos - i;
                    const origenLabel = {
                        'app_android': '&#128241; App Android',
                        'migracion': '&#128190; Registro original',
                        'web': '&#127760; Web'
                    }[h.origen] || esc(h.origen);

                    html += `<div class="timeline-item">
                        <span class="timeline-numero">Escaneo #${numero}</span>
                        <div class="timeline-fecha">${formatFechaFull(h.fecha_escaneo)}</div>
                        <div class="timeline-origen">${origenLabel}</div>
                    </div>`;
                });
                html += '</div>';
            }

            body.innerHTML = html;
        } catch (e) {
            body.innerHTML = '<div class="modal-loading" style="color:var(--red)">Error al cargar historial</div>';
            console.error('Error cargando historial:', e);
        }
    }

    function cerrarModal(event) {
        if (event.target === document.getElementById('modalHistorial')) {
            cerrarModalHistorial();
        }
    }

    function cerrarModalHistorial() {
        document.getElementById('modalHistorial').classList.remove('active');
    }

    // Cerrar modal con Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') cerrarModalHistorial();
    });
    </script>
</body>
</html>
