<?php
/**
 * Dashboard de Gestion - Matafuegos Escaneados
 * HST SRL - Sistema Belga
 *
 * Panel de control profesional con KPIs, graficos y alertas.
 */

require_once __DIR__ . '/config.php';

// === API de estadisticas ===
if (isset($_GET['api']) && $_GET['api'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    $db = getDB();

    $hoy = date('Y-m-d');

    // Contadores basicos
    $total     = (int)$db->query("SELECT COUNT(*) FROM extintores")->fetchColumn();
    $cargados  = (int)$db->query("SELECT COUNT(*) FROM extintores WHERE estado='cargado'")->fetchColumn();
    $pendientes= (int)$db->query("SELECT COUNT(*) FROM extintores WHERE estado='pendiente'")->fetchColumn();
    $errores   = (int)$db->query("SELECT COUNT(*) FROM extintores WHERE estado='error'")->fetchColumn();

    // Todos los registros cargados para analisis
    $stmt = $db->query("SELECT * FROM extintores WHERE estado='cargado' ORDER BY fecha_escaneo DESC");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Analisis de vencimientos
    $vencidos = 0;
    $porVencer30 = 0;
    $porVencer90 = 0;
    $alDia = 0;
    $sinDato = 0;
    $alertas = [];

    // Por fabricante
    $porFabricante = [];
    // Por recargadora
    $porRecargadora = [];
    // Por agente
    $porAgente = [];
    // Por uso
    $porUso = [];
    // Por domicilio
    $porDomicilio = [];
    // Timeline de escaneos por dia
    $porDia = [];
    // Venc vida util
    $vidaUtilVencidos = 0;

    foreach ($registros as $r) {
        // Fabricante
        $fab = $r['fabricante'] ?: 'Sin dato';
        $porFabricante[$fab] = ($porFabricante[$fab] ?? 0) + 1;

        // Recargadora
        $rec = $r['recargadora'] ?: 'Sin dato';
        $porRecargadora[$rec] = ($porRecargadora[$rec] ?? 0) + 1;

        // Agente
        $ag = $r['agente_extintor'] ?: 'Sin dato';
        $porAgente[$ag] = ($porAgente[$ag] ?? 0) + 1;

        // Uso
        $uso = $r['uso'] ?: 'Sin dato';
        $porUso[$uso] = ($porUso[$uso] ?? 0) + 1;

        // Domicilio
        $dom = $r['domicilio'] ?: 'Sin domicilio';
        $porDomicilio[$dom] = ($porDomicilio[$dom] ?? 0) + 1;

        // Timeline
        $dia = substr($r['fecha_escaneo'], 0, 10);
        $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;

        // Analisis vencimiento mantenimiento
        $fechaVenc = $r['fecha_venc_mantenimiento'] ?? '';
        $diasVenc = parsearDiasVencimiento($fechaVenc);

        if ($diasVenc === null) {
            $sinDato++;
        } elseif ($diasVenc < 0) {
            $vencidos++;
            $alertas[] = [
                'tipo' => 'vencido',
                'dias' => $diasVenc,
                'domicilio' => $r['domicilio'] ?: '-',
                'fabricante' => $r['fabricante'] ?: '-',
                'nro_extintor' => $r['nro_extintor'] ?: '-',
                'nro_tarjeta' => $r['nro_tarjeta'] ?: '-',
                'fecha_venc' => $fechaVenc,
                'agente' => $r['agente_extintor'] ?: '-',
                'capacidad' => $r['capacidad'] ?: '-',
            ];
        } elseif ($diasVenc <= 30) {
            $porVencer30++;
            $alertas[] = [
                'tipo' => 'pronto',
                'dias' => $diasVenc,
                'domicilio' => $r['domicilio'] ?: '-',
                'fabricante' => $r['fabricante'] ?: '-',
                'nro_extintor' => $r['nro_extintor'] ?: '-',
                'nro_tarjeta' => $r['nro_tarjeta'] ?: '-',
                'fecha_venc' => $fechaVenc,
                'agente' => $r['agente_extintor'] ?: '-',
                'capacidad' => $r['capacidad'] ?: '-',
            ];
        } elseif ($diasVenc <= 90) {
            $porVencer90++;
        } else {
            $alDia++;
        }

        // Vida util
        $vidaUtil = $r['venc_vida_util'] ?? '';
        $diasVU = parsearDiasVencimiento($vidaUtil);
        if ($diasVU !== null && $diasVU < 0) {
            $vidaUtilVencidos++;
        }
    }

    // Ordenar alertas por urgencia
    usort($alertas, function($a, $b) { return $a['dias'] - $b['dias']; });

    // Ordenar distribuciones
    arsort($porFabricante);
    arsort($porRecargadora);
    arsort($porAgente);

    echo json_encode([
        'contadores' => [
            'total' => $total,
            'cargados' => $cargados,
            'pendientes' => $pendientes,
            'errores' => $errores,
        ],
        'vencimientos' => [
            'vencidos' => $vencidos,
            'por_vencer_30' => $porVencer30,
            'por_vencer_90' => $porVencer90,
            'al_dia' => $alDia,
            'sin_dato' => $sinDato,
            'vida_util_vencidos' => $vidaUtilVencidos,
        ],
        'alertas' => array_slice($alertas, 0, 20),
        'distribuciones' => [
            'fabricante' => $porFabricante,
            'recargadora' => $porRecargadora,
            'agente' => $porAgente,
            'uso' => $porUso,
            'domicilio' => $porDomicilio,
        ],
        'timeline' => $porDia,
    ]);
    exit;
}

function parsearDiasVencimiento(string $fecha): ?int {
    if (empty($fecha) || $fecha === '-') return null;
    $partes = explode('/', $fecha);
    $fechaObj = null;
    if (count($partes) === 2) {
        // MM/YYYY - ultimo dia del mes
        $fechaObj = new DateTime($partes[1] . '-' . str_pad($partes[0], 2, '0', STR_PAD_LEFT) . '-01');
        $fechaObj->modify('last day of this month');
    } elseif (count($partes) === 3) {
        $fechaObj = new DateTime($partes[2] . '-' . str_pad($partes[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($partes[0], 2, '0', STR_PAD_LEFT));
    }
    if (!$fechaObj) return null;
    $hoy = new DateTime('today');
    return (int)$hoy->diff($fechaObj)->format('%r%a');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Matafuegos HST</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --card-border: #334155;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --green: #22c55e;
            --green-dim: rgba(34,197,94,0.15);
            --yellow: #eab308;
            --yellow-dim: rgba(234,179,8,0.15);
            --orange: #f97316;
            --orange-dim: rgba(249,115,22,0.15);
            --red: #ef4444;
            --red-dim: rgba(239,68,68,0.15);
            --blue: #3b82f6;
            --blue-dim: rgba(59,130,246,0.15);
            --purple: #a855f7;
            --purple-dim: rgba(168,85,247,0.15);
            --cyan: #06b6d4;
            --radius: 16px;
            --radius-sm: 10px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* === TOPBAR === */
        .topbar {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-bottom: 1px solid var(--card-border);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .topbar h1 { font-size: 20px; font-weight: 700; }
        .topbar .subtitle { font-size: 12px; color: var(--text-muted); }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }
        .topbar .link-visor {
            color: var(--blue);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 6px 14px;
            border: 1px solid var(--blue);
            border-radius: 8px;
            transition: all 0.2s;
        }
        .topbar .link-visor:hover { background: var(--blue); color: white; }
        .topbar .link-visor[style*="purple"]:hover { background: var(--purple) !important; color: white !important; }

        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        /* === KPI CARDS === */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .kpi {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        .kpi::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        .kpi.green::before { background: var(--green); }
        .kpi.red::before { background: var(--red); }
        .kpi.yellow::before { background: var(--yellow); }
        .kpi.blue::before { background: var(--blue); }

        .kpi-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .kpi-value { font-size: 36px; font-weight: 800; margin: 6px 0 2px; line-height: 1; }
        .kpi-sub { font-size: 12px; color: var(--text-muted); }
        .kpi.green .kpi-value { color: var(--green); }
        .kpi.red .kpi-value { color: var(--red); }
        .kpi.yellow .kpi-value { color: var(--yellow); }
        .kpi.blue .kpi-value { color: var(--blue); }

        .kpi-icon {
            position: absolute;
            top: 16px;
            right: 16px;
            font-size: 32px;
            opacity: 0.15;
        }

        /* === GRID LAYOUT === */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .full-width { margin-bottom: 20px; }

        /* === CARD === */
        .card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 20px;
        }
        .card-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* === DONUT CHART (CSS) === */
        .donut-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
        }
        .donut {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .donut-center {
            position: absolute;
            text-align: center;
        }
        .donut-center .num { font-size: 32px; font-weight: 800; }
        .donut-center .label { font-size: 11px; color: var(--text-muted); }
        .donut-legend { display: flex; flex-direction: column; gap: 10px; }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .legend-count { font-weight: 700; margin-left: auto; padding-left: 12px; }

        /* === BAR CHART (CSS) === */
        .bar-list { display: flex; flex-direction: column; gap: 10px; }
        .bar-item { }
        .bar-label-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .bar-label { font-size: 13px; font-weight: 500; }
        .bar-count { font-size: 13px; font-weight: 700; color: var(--text-muted); }
        .bar-track { height: 8px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 4px; transition: width 0.8s ease; }

        /* === ALERT TABLE === */
        .alert-table { width: 100%; border-collapse: collapse; }
        .alert-table th {
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 8px 10px;
            border-bottom: 1px solid var(--card-border);
        }
        .alert-table td {
            padding: 10px;
            font-size: 13px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .alert-table tr:hover { background: rgba(255,255,255,0.03); }

        .tag {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .tag.vencido { background: var(--red-dim); color: var(--red); }
        .tag.pronto { background: var(--yellow-dim); color: var(--yellow); }
        .tag.ok { background: var(--green-dim); color: var(--green); }

        /* === MINI STAT === */
        .mini-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .mini-stat {
            text-align: center;
            padding: 14px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.03);
        }
        .mini-stat .val { font-size: 24px; font-weight: 800; }
        .mini-stat .lbl { font-size: 11px; color: var(--text-muted); text-transform: uppercase; }

        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .container { padding: 12px; }
            .topbar { padding: 12px 16px; }
            .mini-stats { grid-template-columns: 1fr; }
            .donut-container { flex-direction: column; }
        }

        /* === LOADING === */
        .loading-overlay {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            color: var(--text-muted);
            font-size: 14px;
        }
        .spinner-lg { width: 40px; height: 40px; border: 3px solid var(--card-border); border-top-color: var(--blue); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <div>
                <h1>Dashboard de Gestion</h1>
                <div class="subtitle">Matafuegos - HST SRL / Belga</div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="live-dot"></span>
            <span style="font-size:12px;color:var(--text-muted)">Actualizado: <span id="lastUpdate">-</span></span>
            <a href="informe_controles.php" class="link-visor" style="border-color:var(--purple);color:var(--purple)">&#128203; Controles Periodicos</a>
            <a href="visor.php" class="link-visor">&#128196; Ver Listado</a>
        </div>
    </div>

    <div class="container">
        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi blue">
                <span class="kpi-icon">&#128203;</span>
                <div class="kpi-label">Total Escaneados</div>
                <div class="kpi-value" id="kpiTotal">-</div>
                <div class="kpi-sub" id="kpiTotalSub">cargando...</div>
            </div>
            <div class="kpi green">
                <span class="kpi-icon">&#9989;</span>
                <div class="kpi-label">Al Dia</div>
                <div class="kpi-value" id="kpiAlDia">-</div>
                <div class="kpi-sub">mantenimiento vigente</div>
            </div>
            <div class="kpi yellow">
                <span class="kpi-icon">&#9888;</span>
                <div class="kpi-label">Por Vencer (30d)</div>
                <div class="kpi-value" id="kpiPorVencer">-</div>
                <div class="kpi-sub">requieren atencion</div>
            </div>
            <div class="kpi red">
                <span class="kpi-icon">&#10060;</span>
                <div class="kpi-label">Vencidos</div>
                <div class="kpi-value" id="kpiVencidos">-</div>
                <div class="kpi-sub">accion inmediata</div>
            </div>
        </div>

        <!-- Row 2: Donut + Alertas -->
        <div class="grid-2">
            <!-- Donut de vencimiento -->
            <div class="card">
                <div class="card-title">&#128200; Estado de Vencimiento</div>
                <div class="donut-container" id="donutContainer">
                    <div class="loading-overlay"><div class="spinner-lg"></div></div>
                </div>
            </div>

            <!-- Alertas urgentes -->
            <div class="card">
                <div class="card-title">&#128680; Alertas Urgentes</div>
                <div id="alertasContainer" style="max-height:340px;overflow-y:auto">
                    <div class="loading-overlay"><div class="spinner-lg"></div></div>
                </div>
            </div>
        </div>

        <!-- Row 3: Distribuciones -->
        <div class="grid-3">
            <div class="card">
                <div class="card-title">&#127981; Por Fabricante</div>
                <div id="chartFabricante" class="bar-list"></div>
            </div>
            <div class="card">
                <div class="card-title">&#128295; Por Recargadora</div>
                <div id="chartRecargadora" class="bar-list"></div>
            </div>
            <div class="card">
                <div class="card-title">&#128293; Por Agente Extintor</div>
                <div id="chartAgente" class="bar-list"></div>
            </div>
        </div>

        <!-- Row 4: Mini stats + Uso + Domicilios -->
        <div class="grid-3">
            <div class="card">
                <div class="card-title">&#128202; Resumen General</div>
                <div class="mini-stats" id="miniStats">
                    <div class="loading-overlay"><div class="spinner-lg"></div></div>
                </div>
            </div>
            <div class="card">
                <div class="card-title">&#127970; Por Uso</div>
                <div id="chartUso" class="bar-list"></div>
            </div>
            <div class="card">
                <div class="card-title">&#128205; Por Domicilio</div>
                <div id="chartDomicilio" class="bar-list"></div>
            </div>
        </div>
    </div>

    <script>
    const COLORS = ['#3b82f6','#22c55e','#eab308','#ef4444','#a855f7','#f97316','#06b6d4','#ec4899'];

    document.addEventListener('DOMContentLoaded', () => {
        cargarDatos();
        setInterval(cargarDatos, 30000);
    });

    async function cargarDatos() {
        try {
            const resp = await fetch('dashboard.php?api=stats');
            const data = await resp.json();
            renderKPIs(data);
            renderDonut(data.vencimientos);
            renderAlertas(data.alertas);
            renderBarChart('chartFabricante', data.distribuciones.fabricante, COLORS[0]);
            renderBarChart('chartRecargadora', data.distribuciones.recargadora, COLORS[4]);
            renderBarChart('chartAgente', data.distribuciones.agente, COLORS[6]);
            renderBarChart('chartUso', data.distribuciones.uso, COLORS[1]);
            renderBarChart('chartDomicilio', data.distribuciones.domicilio, COLORS[5]);
            renderMiniStats(data);
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('es-AR');
        } catch (e) {
            console.error('Error cargando stats:', e);
        }
    }

    function renderKPIs(data) {
        const c = data.contadores;
        const v = data.vencimientos;
        document.getElementById('kpiTotal').textContent = c.total;
        document.getElementById('kpiTotalSub').textContent = `${c.cargados} cargados / ${c.pendientes} pendientes / ${c.errores} error`;
        document.getElementById('kpiAlDia').textContent = v.al_dia + v.por_vencer_90;
        document.getElementById('kpiPorVencer').textContent = v.por_vencer_30;
        document.getElementById('kpiVencidos').textContent = v.vencidos;
    }

    function renderDonut(v) {
        const total = v.vencidos + v.por_vencer_30 + v.por_vencer_90 + v.al_dia + v.sin_dato;
        if (total === 0) {
            document.getElementById('donutContainer').innerHTML = '<div style="text-align:center;color:var(--text-muted)">Sin datos</div>';
            return;
        }

        const segments = [
            { label: 'Vencidos', count: v.vencidos, color: '#ef4444' },
            { label: 'Vence en 30d', count: v.por_vencer_30, color: '#eab308' },
            { label: 'Vence en 90d', count: v.por_vencer_90, color: '#f97316' },
            { label: 'Al dia', count: v.al_dia, color: '#22c55e' },
            { label: 'Sin dato', count: v.sin_dato, color: '#475569' },
        ].filter(s => s.count > 0);

        // Build conic gradient
        let gradParts = [];
        let cumPct = 0;
        segments.forEach(s => {
            const pct = (s.count / total) * 100;
            gradParts.push(`${s.color} ${cumPct}% ${cumPct + pct}%`);
            cumPct += pct;
        });

        const legendHtml = segments.map(s =>
            `<div class="legend-item">
                <span class="legend-dot" style="background:${s.color}"></span>
                <span>${s.label}</span>
                <span class="legend-count">${s.count}</span>
            </div>`
        ).join('');

        document.getElementById('donutContainer').innerHTML = `
            <div class="donut" style="background:conic-gradient(${gradParts.join(',')})">
                <div style="width:120px;height:120px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center">
                    <div class="donut-center">
                        <div class="num">${total}</div>
                        <div class="label">extintores</div>
                    </div>
                </div>
            </div>
            <div class="donut-legend">${legendHtml}</div>
        `;
    }

    function renderAlertas(alertas) {
        const container = document.getElementById('alertasContainer');
        if (alertas.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--green)"><div style="font-size:40px;margin-bottom:8px">&#10003;</div>No hay alertas. Todo al dia.</div>';
            return;
        }

        let html = `<table class="alert-table">
            <thead><tr>
                <th>Estado</th>
                <th>Domicilio</th>
                <th>Nro. Ext.</th>
                <th>Vencimiento</th>
                <th>Dias</th>
            </tr></thead><tbody>`;

        alertas.forEach(a => {
            const tagClass = a.tipo === 'vencido' ? 'vencido' : 'pronto';
            const tagText = a.tipo === 'vencido' ? 'VENCIDO' : 'POR VENCER';
            const diasText = a.tipo === 'vencido' ? `${Math.abs(a.dias)}d atrasado` : `${a.dias}d`;
            html += `<tr>
                <td><span class="tag ${tagClass}">${tagText}</span></td>
                <td>${esc(a.domicilio)}</td>
                <td>${esc(a.nro_extintor)}</td>
                <td>${esc(a.fecha_venc)}</td>
                <td style="font-weight:700;color:${a.tipo === 'vencido' ? 'var(--red)' : 'var(--yellow)'}">${diasText}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderBarChart(containerId, data, color) {
        const container = document.getElementById(containerId);
        const entries = Object.entries(data);
        if (entries.length === 0) {
            container.innerHTML = '<div style="color:var(--text-muted);font-size:13px">Sin datos</div>';
            return;
        }
        const max = Math.max(...entries.map(e => e[1]));

        let html = '';
        entries.slice(0, 6).forEach(([label, count], i) => {
            const pct = max > 0 ? (count / max) * 100 : 0;
            const c = COLORS[i % COLORS.length] || color;
            html += `<div class="bar-item">
                <div class="bar-label-row">
                    <span class="bar-label">${esc(label)}</span>
                    <span class="bar-count">${count}</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" style="width:${pct}%;background:${c}"></div>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    }

    function renderMiniStats(data) {
        const c = data.contadores;
        const v = data.vencimientos;
        const pctVigente = c.cargados > 0 ? Math.round(((v.al_dia + v.por_vencer_90) / c.cargados) * 100) : 0;
        const pctVencido = c.cargados > 0 ? Math.round((v.vencidos / c.cargados) * 100) : 0;

        document.getElementById('miniStats').innerHTML = `
            <div class="mini-stat">
                <div class="val" style="color:var(--green)">${pctVigente}%</div>
                <div class="lbl">Vigentes</div>
            </div>
            <div class="mini-stat">
                <div class="val" style="color:var(--red)">${pctVencido}%</div>
                <div class="lbl">Vencidos</div>
            </div>
            <div class="mini-stat">
                <div class="val" style="color:var(--purple)">${v.vida_util_vencidos}</div>
                <div class="lbl">Vida Util Vencida</div>
            </div>
        `;
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
