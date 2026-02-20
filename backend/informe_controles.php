<?php
/**
 * Informe de Controles Periodicos - Matafuegos
 * HST SRL - Sistema Belga
 *
 * Pagina de resumen con KPIs, tabla filtrable, exportacion CSV e impresion.
 */

require_once __DIR__ . '/config.php';

$db = getDB();

// === EXPORTAR CSV ===
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    $filtroEstado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
    $filtroDomicilio = isset($_GET['domicilio']) ? trim($_GET['domicilio']) : '';
    $filtroDesde = isset($_GET['desde']) ? trim($_GET['desde']) : '';
    $filtroHasta = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';
    $filtroBusqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

    $where = [];
    $params = [];

    if ($filtroEstado !== '' && in_array($filtroEstado, ['Cargado','Descargado','Sobrecargado'])) {
        $where[] = "cp.estado_carga = :estado";
        $params[':estado'] = $filtroEstado;
    }
    if ($filtroDomicilio !== '') {
        $where[] = "e.domicilio LIKE :dom";
        $params[':dom'] = "%$filtroDomicilio%";
    }
    if ($filtroDesde !== '') {
        $where[] = "cp.fecha_control >= :desde";
        $params[':desde'] = $filtroDesde . ' 00:00:00';
    }
    if ($filtroHasta !== '') {
        $where[] = "cp.fecha_control <= :hasta";
        $params[':hasta'] = $filtroHasta . ' 23:59:59';
    }
    if ($filtroBusqueda !== '') {
        $where[] = "(e.domicilio LIKE :q1 OR e.nro_extintor LIKE :q2 OR e.nro_tarjeta LIKE :q3 OR cp.chapa_baliza LIKE :q4 OR cp.comentario LIKE :q5)";
        $params[':q1'] = "%$filtroBusqueda%";
        $params[':q2'] = "%$filtroBusqueda%";
        $params[':q3'] = "%$filtroBusqueda%";
        $params[':q4'] = "%$filtroBusqueda%";
        $params[':q5'] = "%$filtroBusqueda%";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT cp.id, cp.fecha_control, cp.estado_carga, cp.chapa_baliza, cp.comentario, cp.origen,
                   e.domicilio, e.nro_extintor, e.nro_tarjeta, e.fabricante, e.recargadora,
                   e.agente_extintor, e.capacidad, e.url, e.nro_orden
            FROM controles_periodicos cp
            INNER JOIN extintores e ON cp.extintor_id = e.id
            $whereSQL
            ORDER BY cp.fecha_control DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $todos = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="controles_periodicos_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Fecha Control', 'Estado Carga', 'Chapa/Baliza', 'Comentario',
        'Domicilio', 'Nro Extintor', 'Nro Tarjeta', 'Fabricante', 'Recargadora',
        'Agente Extintor', 'Capacidad', 'Nro Orden', 'Origen']);

    foreach ($todos as $r) {
        fputcsv($out, [
            $r['id'], $r['fecha_control'], $r['estado_carga'], $r['chapa_baliza'],
            $r['comentario'] ?? '', $r['domicilio'] ?? '', $r['nro_extintor'] ?? '',
            $r['nro_tarjeta'] ?? '', $r['fabricante'] ?? '', $r['recargadora'] ?? '',
            $r['agente_extintor'] ?? '', $r['capacidad'] ?? '', $r['nro_orden'] ?? '',
            $r['origen'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// === API DE DATOS (JSON) ===
if (isset($_GET['api']) && $_GET['api'] === 'datos') {
    header('Content-Type: application/json; charset=utf-8');

    $filtroEstado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
    $filtroDomicilio = isset($_GET['domicilio']) ? trim($_GET['domicilio']) : '';
    $filtroDesde = isset($_GET['desde']) ? trim($_GET['desde']) : '';
    $filtroHasta = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';
    $filtroBusqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
    $porPagina = 25;
    $pagina = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($pagina - 1) * $porPagina;

    $where = [];
    $params = [];

    if ($filtroEstado !== '' && in_array($filtroEstado, ['Cargado','Descargado','Sobrecargado'])) {
        $where[] = "cp.estado_carga = :estado";
        $params[':estado'] = $filtroEstado;
    }
    if ($filtroDomicilio !== '') {
        $where[] = "e.domicilio LIKE :dom";
        $params[':dom'] = "%$filtroDomicilio%";
    }
    if ($filtroDesde !== '') {
        $where[] = "cp.fecha_control >= :desde";
        $params[':desde'] = $filtroDesde . ' 00:00:00';
    }
    if ($filtroHasta !== '') {
        $where[] = "cp.fecha_control <= :hasta";
        $params[':hasta'] = $filtroHasta . ' 23:59:59';
    }
    if ($filtroBusqueda !== '') {
        $where[] = "(e.domicilio LIKE :q1 OR e.nro_extintor LIKE :q2 OR e.nro_tarjeta LIKE :q3 OR cp.chapa_baliza LIKE :q4 OR cp.comentario LIKE :q5)";
        $params[':q1'] = "%$filtroBusqueda%";
        $params[':q2'] = "%$filtroBusqueda%";
        $params[':q3'] = "%$filtroBusqueda%";
        $params[':q4'] = "%$filtroBusqueda%";
        $params[':q5'] = "%$filtroBusqueda%";
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Contar total
    $countSQL = "SELECT COUNT(*) FROM controles_periodicos cp INNER JOIN extintores e ON cp.extintor_id = e.id $whereSQL";
    $countStmt = $db->prepare($countSQL);
    $countStmt->execute($params);
    $totalRegistros = (int)$countStmt->fetchColumn();

    // KPIs
    $kpiTotal = (int)$db->query("SELECT COUNT(*) FROM controles_periodicos")->fetchColumn();
    $kpiCargado = (int)$db->query("SELECT COUNT(*) FROM controles_periodicos WHERE estado_carga='Cargado'")->fetchColumn();
    $kpiDescargado = (int)$db->query("SELECT COUNT(*) FROM controles_periodicos WHERE estado_carga='Descargado'")->fetchColumn();
    $kpiSobrecargado = (int)$db->query("SELECT COUNT(*) FROM controles_periodicos WHERE estado_carga='Sobrecargado'")->fetchColumn();

    $hoy = date('Y-m-d');
    $kpiHoy = (int)$db->prepare("SELECT COUNT(*) FROM controles_periodicos WHERE DATE(fecha_control) = :hoy");
    $stmtHoy = $db->prepare("SELECT COUNT(*) FROM controles_periodicos WHERE DATE(fecha_control) = :hoy");
    $stmtHoy->execute([':hoy' => $hoy]);
    $kpiHoy = (int)$stmtHoy->fetchColumn();

    $semanaAtras = date('Y-m-d', strtotime('-7 days'));
    $stmtSem = $db->prepare("SELECT COUNT(*) FROM controles_periodicos WHERE fecha_control >= :desde");
    $stmtSem->execute([':desde' => $semanaAtras . ' 00:00:00']);
    $kpiSemana = (int)$stmtSem->fetchColumn();

    // Extintores unicos controlados
    $kpiExtintores = (int)$db->query("SELECT COUNT(DISTINCT extintor_id) FROM controles_periodicos")->fetchColumn();

    // Domicilios con controles
    $domiciliosStmt = $db->query(
        "SELECT e.domicilio, COUNT(*) as total
         FROM controles_periodicos cp
         INNER JOIN extintores e ON cp.extintor_id = e.id
         WHERE e.domicilio IS NOT NULL AND e.domicilio != ''
         GROUP BY e.domicilio
         ORDER BY total DESC
         LIMIT 10"
    );
    $domicilios = $domiciliosStmt->fetchAll();

    // Datos paginados
    $dataSQL = "SELECT cp.id, cp.fecha_control, cp.estado_carga, cp.chapa_baliza, cp.comentario, cp.origen,
                       e.id as extintor_id, e.domicilio, e.nro_extintor, e.nro_tarjeta, e.fabricante,
                       e.recargadora, e.agente_extintor, e.capacidad, e.nro_orden
                FROM controles_periodicos cp
                INNER JOIN extintores e ON cp.extintor_id = e.id
                $whereSQL
                ORDER BY cp.fecha_control DESC
                LIMIT $porPagina OFFSET $offset";

    $dataStmt = $db->prepare($dataSQL);
    $dataStmt->execute($params);
    $registros = $dataStmt->fetchAll();

    echo json_encode([
        'kpis' => [
            'total' => $kpiTotal,
            'cargado' => $kpiCargado,
            'descargado' => $kpiDescargado,
            'sobrecargado' => $kpiSobrecargado,
            'hoy' => $kpiHoy,
            'semana' => $kpiSemana,
            'extintores_unicos' => $kpiExtintores,
        ],
        'domicilios' => $domicilios,
        'registros' => $registros,
        'total_registros' => $totalRegistros,
        'pagina' => $pagina,
        'total_paginas' => ceil($totalRegistros / $porPagina),
        'por_pagina' => $porPagina,
    ]);
    exit;
}

// === CONTADORES INICIALES ===
$totalControles = (int)$db->query("SELECT COUNT(*) FROM controles_periodicos")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe Controles Periodicos - HST</title>
    <style>
        :root {
            --bg: #f0f2f5;
            --card: #ffffff;
            --header-from: #4a1a8a;
            --header-to: #7c3aed;
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
            --purple: #7c3aed;
            --purple-bg: #ede9fe;
            --purple-text: #5b21b6;
            --orange: #f97316;
            --orange-bg: #fff7ed;
            --orange-text: #9a3412;
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
        .header .subtitle { font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 2px; }
        .header-nav { display: flex; gap: 8px; margin-top: 8px; }
        .nav-link {
            color: white;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.2s;
        }
        .nav-link:hover { background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.6); }
        .header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.8);
        }
        .total-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 15px;
        }

        /* === KPI CARDS === */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi {
            background: var(--card);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
            border-left: 4px solid transparent;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .kpi:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .kpi.active { outline: 2px solid var(--purple); outline-offset: 2px; }
        .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; color: var(--text-muted); }
        .kpi-value { font-size: 28px; font-weight: 800; margin: 4px 0; }
        .kpi-sub { font-size: 11px; color: var(--text-muted); }

        .kpi.verde { border-left-color: var(--green); }
        .kpi.verde .kpi-value { color: var(--green); }
        .kpi.rojo { border-left-color: var(--red); }
        .kpi.rojo .kpi-value { color: var(--red); }
        .kpi.naranja { border-left-color: var(--orange); }
        .kpi.naranja .kpi-value { color: var(--orange); }
        .kpi.azul { border-left-color: var(--blue); }
        .kpi.azul .kpi-value { color: var(--blue); }
        .kpi.purpura { border-left-color: var(--purple); }
        .kpi.purpura .kpi-value { color: var(--purple); }

        /* === MINI KPIs === */
        .mini-kpi-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .mini-kpi {
            background: var(--card);
            border-radius: var(--radius);
            padding: 14px 18px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        .mini-kpi .val { font-size: 22px; font-weight: 800; color: var(--purple); }
        .mini-kpi .lbl { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; }

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
        .toolbar-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }
        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            background: var(--card);
            transition: border-color 0.2s;
        }
        .search-box input:focus { outline: none; border-color: var(--purple); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 16px; color: var(--text-muted); }

        .filter-group {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .filter-group input[type="date"],
        .filter-group select {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: var(--card);
        }
        .filter-group input[type="date"]:focus,
        .filter-group select:focus { outline: none; border-color: var(--purple); }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-csv { background: var(--green); color: white; }
        .btn-csv:hover { background: #16a34a; }
        .btn-print { background: #6b7280; color: white; }
        .btn-print:hover { background: #4b5563; }
        .btn-limpiar { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
        .btn-limpiar:hover { background: var(--red-bg); color: var(--red); border-color: var(--red); }

        /* === TABLA === */
        .tabla-container {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        thead th {
            background: #f9fafb;
            padding: 12px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        tbody tr:hover { background: #faf5ff; }

        /* === BADGES === */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge.cargado { background: var(--green-bg); color: var(--green-text); }
        .badge.descargado { background: var(--red-bg); color: var(--red-text); }
        .badge.sobrecargado { background: var(--orange-bg); color: var(--orange-text); }

        /* === PAGINATION === */
        .paginacion {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            padding: 16px;
        }
        .paginacion button {
            padding: 6px 12px;
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .paginacion button:hover { background: var(--purple-bg); border-color: var(--purple); }
        .paginacion button.active { background: var(--purple); color: white; border-color: var(--purple); }
        .paginacion button:disabled { opacity: 0.4; cursor: not-allowed; }
        .pag-info { font-size: 12px; color: var(--text-muted); margin: 0 8px; }

        /* === CARDS MOBILE === */
        .cards-container { display: none; }
        .control-card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
            margin-bottom: 10px;
        }
        .control-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .control-card-fecha { font-size: 12px; color: var(--text-muted); }
        .control-card-body { font-size: 13px; }
        .control-card-body .field { margin-bottom: 4px; }
        .control-card-body .field-label { font-weight: 600; color: var(--text-muted); font-size: 11px; text-transform: uppercase; }
        .control-card-body .field-value { color: var(--text); }
        .control-card-comment {
            margin-top: 8px;
            padding: 8px 12px;
            background: #f9fafb;
            border-radius: 6px;
            font-size: 12px;
            color: #555;
            font-style: italic;
        }

        /* === NO DATA === */
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .no-data .icon { font-size: 48px; margin-bottom: 12px; }
        .no-data .msg { font-size: 16px; font-weight: 600; }
        .no-data .sub { font-size: 13px; margin-top: 4px; }

        /* === LOADING === */
        .loading { text-align: center; padding: 40px; color: var(--text-muted); }
        .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid var(--border); border-top-color: var(--purple); border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* === PRINT === */
        .print-header { display: none; }

        @media print {
            body { background: white !important; padding: 0 !important; }
            .header, .toolbar, .mini-kpi-row, .kpi-grid, .paginacion, .cards-container,
            .btn-print, .btn-csv, .btn-limpiar { display: none !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 16px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .print-header h2 { font-size: 18px; }
            .print-header p { font-size: 12px; color: #666; }
            .tabla-container { box-shadow: none !important; border-radius: 0 !important; }
            table { font-size: 11px !important; }
            thead th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr:hover { background: none !important; }
            .badge { border: 1px solid #ccc; }
        }

        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .mini-kpi-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .mini-kpi-row { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left, .toolbar-right { width: 100%; }
            .search-box { max-width: none; }
            .filter-group { flex-wrap: wrap; }
            .tabla-container { display: none; }
            .cards-container { display: block; }
            .header { flex-direction: column; text-align: center; }
            .header-nav { justify-content: center; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>&#128203; Informe de Controles Periodicos</h1>
                <div class="subtitle">HST SRL - Sistema Belga</div>
                <div class="header-nav">
                    <a href="dashboard.php" class="nav-link">&#128202; Dashboard</a>
                    <a href="visor.php" class="nav-link">&#128196; Visor Extintores</a>
                </div>
            </div>
            <div class="header-right">
                <span>Total controles:</span>
                <span class="total-badge" id="totalBadge"><?= $totalControles ?></span>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi verde" onclick="filtrarEstado('Cargado')">
                <div class="kpi-label">Cargados</div>
                <div class="kpi-value" id="kpiCargado">-</div>
                <div class="kpi-sub">estado OK</div>
            </div>
            <div class="kpi rojo" onclick="filtrarEstado('Descargado')">
                <div class="kpi-label">Descargados</div>
                <div class="kpi-value" id="kpiDescargado">-</div>
                <div class="kpi-sub">requiere recarga</div>
            </div>
            <div class="kpi naranja" onclick="filtrarEstado('Sobrecargado')">
                <div class="kpi-label">Sobrecargados</div>
                <div class="kpi-value" id="kpiSobrecargado">-</div>
                <div class="kpi-sub">exceso de carga</div>
            </div>
            <div class="kpi purpura" onclick="filtrarEstado('')">
                <div class="kpi-label">Total Controles</div>
                <div class="kpi-value" id="kpiTotal">-</div>
                <div class="kpi-sub">todos los registros</div>
            </div>
        </div>

        <!-- Mini KPIs -->
        <div class="mini-kpi-row">
            <div class="mini-kpi">
                <div class="val" id="kpiHoy">-</div>
                <div class="lbl">Controles hoy</div>
            </div>
            <div class="mini-kpi">
                <div class="val" id="kpiSemana">-</div>
                <div class="lbl">Ultimos 7 dias</div>
            </div>
            <div class="mini-kpi">
                <div class="val" id="kpiExtintores">-</div>
                <div class="lbl">Extintores controlados</div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <span class="search-icon">&#128269;</span>
                    <input type="text" id="inputBusqueda" placeholder="Buscar domicilio, extintor, chapa..."
                           autocomplete="off" />
                </div>
                <div class="filter-group">
                    <label>Desde:</label>
                    <input type="date" id="filtroDesde" />
                    <label>Hasta:</label>
                    <input type="date" id="filtroHasta" />
                </div>
                <div class="filter-group">
                    <select id="filtroEstadoSelect">
                        <option value="">Todos los estados</option>
                        <option value="Cargado">Cargado</option>
                        <option value="Descargado">Descargado</option>
                        <option value="Sobrecargado">Sobrecargado</option>
                    </select>
                </div>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-limpiar" onclick="limpiarFiltros()">Limpiar filtros</button>
                <button class="btn btn-print" onclick="imprimirTabla()">&#128424; Imprimir</button>
                <button class="btn btn-csv" onclick="exportarCSV()">&#128190; Exportar CSV</button>
            </div>
        </div>

        <!-- Print header -->
        <div class="print-header" id="printHeader">
            <h2>Informe de Controles Periodicos - HST SRL</h2>
            <p id="printSubtitle"></p>
        </div>

        <!-- Tabla Desktop -->
        <div class="tabla-container" id="tablaContainer">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Chapa/Baliza</th>
                        <th>Domicilio</th>
                        <th>Nro Extintor</th>
                        <th>Nro Tarjeta</th>
                        <th>Fabricante</th>
                        <th>Capacidad</th>
                        <th>Orden</th>
                        <th>Comentario</th>
                    </tr>
                </thead>
                <tbody id="tablaBody">
                    <tr><td colspan="10" class="loading"><div class="spinner"></div><br>Cargando...</td></tr>
                </tbody>
            </table>
            <div class="paginacion" id="paginacion"></div>
        </div>

        <!-- Cards Mobile -->
        <div class="cards-container" id="cardsContainer">
            <div class="loading"><div class="spinner"></div><br>Cargando...</div>
        </div>
        <div class="paginacion" id="paginacionMobile" style="display:none"></div>
    </div>

    <script>
    // === STATE ===
    let state = {
        busqueda: '',
        estado: '',
        desde: '',
        hasta: '',
        pagina: 1,
        datos: null
    };

    // === INIT ===
    document.addEventListener('DOMContentLoaded', () => {
        cargarDatos();
        setInterval(cargarDatos, 60000); // Refresh cada minuto

        // Busqueda con debounce
        let timeout;
        document.getElementById('inputBusqueda').addEventListener('input', (e) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                state.busqueda = e.target.value.trim();
                state.pagina = 1;
                cargarDatos();
            }, 400);
        });

        // Filtros de fecha
        document.getElementById('filtroDesde').addEventListener('change', (e) => {
            state.desde = e.target.value;
            state.pagina = 1;
            cargarDatos();
        });
        document.getElementById('filtroHasta').addEventListener('change', (e) => {
            state.hasta = e.target.value;
            state.pagina = 1;
            cargarDatos();
        });

        // Filtro de estado (select)
        document.getElementById('filtroEstadoSelect').addEventListener('change', (e) => {
            state.estado = e.target.value;
            state.pagina = 1;
            cargarDatos();
            actualizarKPIActivo();
        });
    });

    // === CARGAR DATOS ===
    async function cargarDatos() {
        try {
            const params = new URLSearchParams({ api: 'datos', p: state.pagina });
            if (state.busqueda) params.set('q', state.busqueda);
            if (state.estado) params.set('estado', state.estado);
            if (state.desde) params.set('desde', state.desde);
            if (state.hasta) params.set('hasta', state.hasta);

            const resp = await fetch('informe_controles.php?' + params.toString());
            const data = await resp.json();
            state.datos = data;

            renderKPIs(data.kpis);
            renderTabla(data.registros);
            renderCards(data.registros);
            renderPaginacion(data);
            document.getElementById('totalBadge').textContent = data.kpis.total;
        } catch (e) {
            console.error('Error cargando datos:', e);
        }
    }

    // === RENDER KPIs ===
    function renderKPIs(kpis) {
        document.getElementById('kpiCargado').textContent = kpis.cargado;
        document.getElementById('kpiDescargado').textContent = kpis.descargado;
        document.getElementById('kpiSobrecargado').textContent = kpis.sobrecargado;
        document.getElementById('kpiTotal').textContent = kpis.total;
        document.getElementById('kpiHoy').textContent = kpis.hoy;
        document.getElementById('kpiSemana').textContent = kpis.semana;
        document.getElementById('kpiExtintores').textContent = kpis.extintores_unicos;
    }

    // === RENDER TABLA ===
    function renderTabla(registros) {
        const body = document.getElementById('tablaBody');
        if (registros.length === 0) {
            body.innerHTML = `<tr><td colspan="10"><div class="no-data">
                <div class="icon">&#128203;</div>
                <div class="msg">No hay controles periodicos</div>
                <div class="sub">Ajuste los filtros o realice un control desde la app</div>
            </div></td></tr>`;
            return;
        }

        body.innerHTML = registros.map(r => {
            const estadoClass = r.estado_carga.toLowerCase();
            const fecha = formatFecha(r.fecha_control);
            return `<tr>
                <td style="white-space:nowrap">${fecha}</td>
                <td><span class="badge ${estadoClass}">${esc(r.estado_carga)}</span></td>
                <td>${esc(r.chapa_baliza)}</td>
                <td>${esc(r.domicilio || '-')}</td>
                <td>${esc(r.nro_extintor || '-')}</td>
                <td>${esc(r.nro_tarjeta || '-')}</td>
                <td>${esc(r.fabricante || '-')}</td>
                <td>${esc(r.capacidad || '-')}</td>
                <td>${esc(r.nro_orden || '-')}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.comentario || '')}">${esc(r.comentario || '-')}</td>
            </tr>`;
        }).join('');
    }

    // === RENDER CARDS (MOBILE) ===
    function renderCards(registros) {
        const container = document.getElementById('cardsContainer');
        if (registros.length === 0) {
            container.innerHTML = `<div class="no-data">
                <div class="icon">&#128203;</div>
                <div class="msg">No hay controles</div>
            </div>`;
            return;
        }

        container.innerHTML = registros.map(r => {
            const estadoClass = r.estado_carga.toLowerCase();
            return `<div class="control-card">
                <div class="control-card-header">
                    <span class="control-card-fecha">${formatFecha(r.fecha_control)}</span>
                    <span class="badge ${estadoClass}">${esc(r.estado_carga)}</span>
                </div>
                <div class="control-card-body">
                    <div class="field"><span class="field-label">Chapa/Baliza: </span><span class="field-value">${esc(r.chapa_baliza)}</span></div>
                    <div class="field"><span class="field-label">Domicilio: </span><span class="field-value">${esc(r.domicilio || '-')}</span></div>
                    <div class="field"><span class="field-label">Extintor: </span><span class="field-value">${esc(r.nro_extintor || '-')} / ${esc(r.nro_tarjeta || '-')}</span></div>
                    ${r.nro_orden ? '<div class="field"><span class="field-label">Orden: </span><span class="field-value">' + esc(r.nro_orden) + '</span></div>' : ''}
                    ${r.comentario ? '<div class="control-card-comment">' + esc(r.comentario) + '</div>' : ''}
                </div>
            </div>`;
        }).join('');
    }

    // === RENDER PAGINACION ===
    function renderPaginacion(data) {
        const html = buildPaginacionHTML(data);
        document.getElementById('paginacion').innerHTML = html;

        const mobileEl = document.getElementById('paginacionMobile');
        if (window.innerWidth <= 768) {
            mobileEl.innerHTML = html;
            mobileEl.style.display = 'flex';
        } else {
            mobileEl.style.display = 'none';
        }
    }

    function buildPaginacionHTML(data) {
        if (data.total_paginas <= 1) return '';

        let html = '';
        html += `<button ${data.pagina <= 1 ? 'disabled' : ''} onclick="irPagina(${data.pagina - 1})">&laquo;</button>`;

        const start = Math.max(1, data.pagina - 2);
        const end = Math.min(data.total_paginas, data.pagina + 2);

        if (start > 1) {
            html += `<button onclick="irPagina(1)">1</button>`;
            if (start > 2) html += `<span class="pag-info">...</span>`;
        }

        for (let i = start; i <= end; i++) {
            html += `<button class="${i === data.pagina ? 'active' : ''}" onclick="irPagina(${i})">${i}</button>`;
        }

        if (end < data.total_paginas) {
            if (end < data.total_paginas - 1) html += `<span class="pag-info">...</span>`;
            html += `<button onclick="irPagina(${data.total_paginas})">${data.total_paginas}</button>`;
        }

        html += `<button ${data.pagina >= data.total_paginas ? 'disabled' : ''} onclick="irPagina(${data.pagina + 1})">&raquo;</button>`;
        html += `<span class="pag-info">${data.total_registros} registros</span>`;

        return html;
    }

    // === ACCIONES ===
    function filtrarEstado(estado) {
        state.estado = estado;
        state.pagina = 1;
        document.getElementById('filtroEstadoSelect').value = estado;
        actualizarKPIActivo();
        cargarDatos();
    }

    function actualizarKPIActivo() {
        document.querySelectorAll('.kpi').forEach(el => el.classList.remove('active'));
        if (state.estado === 'Cargado') document.querySelector('.kpi.verde').classList.add('active');
        else if (state.estado === 'Descargado') document.querySelector('.kpi.rojo').classList.add('active');
        else if (state.estado === 'Sobrecargado') document.querySelector('.kpi.naranja').classList.add('active');
        else if (state.estado === '') document.querySelector('.kpi.purpura').classList.add('active');
    }

    function irPagina(p) {
        state.pagina = p;
        cargarDatos();
        window.scrollTo({ top: 300, behavior: 'smooth' });
    }

    function limpiarFiltros() {
        state.busqueda = '';
        state.estado = '';
        state.desde = '';
        state.hasta = '';
        state.pagina = 1;
        document.getElementById('inputBusqueda').value = '';
        document.getElementById('filtroDesde').value = '';
        document.getElementById('filtroHasta').value = '';
        document.getElementById('filtroEstadoSelect').value = '';
        document.querySelectorAll('.kpi').forEach(el => el.classList.remove('active'));
        cargarDatos();
    }

    function exportarCSV() {
        const params = new URLSearchParams({ exportar: 'csv' });
        if (state.busqueda) params.set('q', state.busqueda);
        if (state.estado) params.set('estado', state.estado);
        if (state.desde) params.set('desde', state.desde);
        if (state.hasta) params.set('hasta', state.hasta);
        window.location.href = 'informe_controles.php?' + params.toString();
    }

    function imprimirTabla() {
        const subtitle = [];
        if (state.estado) subtitle.push('Estado: ' + state.estado);
        if (state.desde) subtitle.push('Desde: ' + state.desde);
        if (state.hasta) subtitle.push('Hasta: ' + state.hasta);
        if (state.busqueda) subtitle.push('Busqueda: ' + state.busqueda);
        subtitle.push('Generado: ' + new Date().toLocaleDateString('es-AR') + ' ' + new Date().toLocaleTimeString('es-AR'));

        document.getElementById('printSubtitle').textContent = subtitle.join(' | ');
        window.print();
    }

    // === UTILS ===
    function formatFecha(str) {
        if (!str) return '-';
        const d = new Date(str.replace(' ', 'T'));
        return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
             + ' ' + d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    }

    function esc(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    </script>
</body>
</html>
