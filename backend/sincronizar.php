<?php
/**
 * Modulo de sincronizacion con el sistema AGC
 * Scrapea datos de matafuegos desde dghpsh.agcontrol.gob.ar
 *
 * Este archivo se incluye desde visor.php y api_sync.php.
 * NO debe ser accedido directamente (protegido por .htaccess).
 */

require_once __DIR__ . '/config.php';

/**
 * Obtiene los datos de un matafuego desde el sitio AGC
 *
 * @param string $url URL completa de AGC (datosEstampilla.jsp?...)
 * @return array|null Datos parseados o null si falla
 */
function obtenerDatosAGC(string $url): ?array {
    // Validar que la URL sea del dominio AGC
    if (stripos($url, AGC_DOMAIN) === false) {
        return null;
    }

    // Hacer GET a la URL de AGC
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: es-AR,es;q=0.9',
        ],
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($html)) {
        error_log("AGC scraper: HTTP $httpCode para $url - Error: $curlError");
        return null;
    }

    // Convertir encoding: AGC usa Latin-1/ISO-8859-1
    $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');

    // Parsear el HTML con DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Buscar pares label/valor en la tabla de AGC
    // Estructura: <td class='frTextoTabla'>Label</td><td class='frTextoTablaRegistroInfo'>Valor</td>
    $labels = $xpath->query("//td[@class='frTextoTabla']");
    $valores = $xpath->query("//td[@class='frTextoTablaRegistroInfo']");

    if ($labels->length === 0) {
        error_log("AGC scraper: No se encontraron datos en HTML para $url");
        return null;
    }

    // Construir array asociativo con los datos
    $datosRaw = [];
    for ($i = 0; $i < $labels->length && $i < $valores->length; $i++) {
        $label = trim(html_entity_decode($labels->item($i)->textContent, ENT_QUOTES, 'UTF-8'));
        $valor = trim(html_entity_decode($valores->item($i)->textContent, ENT_QUOTES, 'UTF-8'));
        $datosRaw[$label] = $valor;
    }

    if (empty($datosRaw)) {
        return null;
    }

    // Mapear campos AGC a nuestra base de datos
    // Usa los nombres de columna que YA EXISTEN en la tabla extintores
    return [
        'domicilio'                  => $datosRaw['Domicilio instalación'] ?? null,
        'fabricante'                 => $datosRaw['Empresa fabricante'] ?? null,
        'recargadora'                => $datosRaw['Empresa recargadora'] ?? null,
        'fecha_mantenimiento'        => $datosRaw['Fecha mantenimiento'] ?? null,
        'fecha_venc_mantenimiento'   => $datosRaw['Fecha vencimiento mantenimiento'] ?? null,
        'agente_extintor'            => $datosRaw['Agente extintor'] ?? null,
        'capacidad'                  => $datosRaw['Capacidad'] ?? null,
        'fecha_fabricacion'          => $datosRaw['Fecha fabricación'] ?? null,
        'venc_vida_util'             => $datosRaw['Fecha vencimiento vida util'] ?? null,
        'venc_ph'                    => $datosRaw['Fecha vencimiento PH'] ?? null,
        'nro_tarjeta'                => $datosRaw['Nro. tarjeta'] ?? null,
        'nro_extintor'               => $datosRaw['Nro. extintor'] ?? null,
        'uso'                        => $datosRaw['Uso'] ?? null,
    ];
}

/**
 * Sincroniza los registros pendientes con AGC
 *
 * @return array ['ok' => int, 'fail' => int, 'total' => int]
 */
function sincronizarPendientes(): array {
    $db = getDB();

    // Obtener registros pendientes o con error (que no superaron reintentos)
    $stmt = $db->query(
        "SELECT id, url FROM extintores
         WHERE estado IN ('pendiente', 'error')
         ORDER BY fecha_escaneo ASC
         LIMIT " . MAX_SYNC_BATCH
    );
    $pendientes = $stmt->fetchAll();

    $ok = 0;
    $fail = 0;

    foreach ($pendientes as $row) {
        $datos = obtenerDatosAGC($row['url']);

        // Verificar que haya ALGUN dato util (no solo domicilio, puede estar vacio)
        $tieneDatos = $datos && (
            !empty($datos['domicilio']) ||
            !empty($datos['fabricante']) ||
            !empty($datos['recargadora']) ||
            !empty($datos['agente_extintor']) ||
            !empty($datos['capacidad'])
        );

        if ($tieneDatos) {
            // Datos obtenidos exitosamente: actualizar registro a "cargado"
            $update = $db->prepare(
                "UPDATE extintores SET
                    estado = 'cargado',
                    domicilio = :domicilio,
                    fabricante = :fabricante,
                    recargadora = :recargadora,
                    fecha_mantenimiento = :fecha_mantenimiento,
                    fecha_venc_mantenimiento = :fecha_venc_mantenimiento,
                    agente_extintor = :agente_extintor,
                    capacidad = :capacidad,
                    fecha_fabricacion = :fecha_fabricacion,
                    venc_vida_util = :venc_vida_util,
                    venc_ph = :venc_ph,
                    nro_tarjeta = :nro_tarjeta,
                    nro_extintor = :nro_extintor,
                    uso = :uso,
                    fecha_sincronizacion = NOW()
                 WHERE id = :id"
            );
            $update->execute([
                ':domicilio'                => $datos['domicilio'],
                ':fabricante'               => $datos['fabricante'],
                ':recargadora'              => $datos['recargadora'],
                ':fecha_mantenimiento'      => $datos['fecha_mantenimiento'],
                ':fecha_venc_mantenimiento' => $datos['fecha_venc_mantenimiento'],
                ':agente_extintor'          => $datos['agente_extintor'],
                ':capacidad'                => $datos['capacidad'],
                ':fecha_fabricacion'        => $datos['fecha_fabricacion'],
                ':venc_vida_util'           => $datos['venc_vida_util'],
                ':venc_ph'                  => $datos['venc_ph'],
                ':nro_tarjeta'              => $datos['nro_tarjeta'],
                ':nro_extintor'             => $datos['nro_extintor'],
                ':uso'                      => $datos['uso'],
                ':id'                       => $row['id'],
            ]);
            $ok++;
        } else {
            // Fallo: marcar como error
            $db->prepare(
                "UPDATE extintores SET estado = 'error' WHERE id = :id"
            )->execute([':id' => $row['id']]);
            $fail++;
        }

        // Pausa entre requests para no sobrecargar AGC
        usleep(500000); // 0.5 segundos
    }

    return [
        'ok'    => $ok,
        'fail'  => $fail,
        'total' => count($pendientes),
    ];
}
