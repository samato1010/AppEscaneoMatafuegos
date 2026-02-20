package com.hst.appescaneomatafuegos.data

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.util.Log
import com.hst.appescaneomatafuegos.ApiService
import com.hst.appescaneomatafuegos.ControlPeriodicoRequest
import com.hst.appescaneomatafuegos.EscaneoRequest

/**
 * Repository que combina Room (offline) + Retrofit (online).
 *
 * Flujo:
 * 1. Al escanear QR -> guardar en Room como "pendiente"
 * 2. Si hay conexion -> enviar al backend -> marcar "enviado"
 * 3. Si falla -> queda "pendiente" para sync posterior
 */
class EscaneoRepository(
    private val dao: EscaneoDao,
    private val api: ApiService,
    private val context: Context
) {

    companion object {
        private const val TAG = "EscaneoRepo"
    }

    /**
     * Resultado de un intento de envio.
     */
    sealed class EnvioResult {
        data object Enviado : EnvioResult()
        data object GuardadoOffline : EnvioResult()
        data object Duplicado : EnvioResult()
        data class ReEscaneado(val mensaje: String) : EnvioResult()
        data class Error(val mensaje: String) : EnvioResult()
    }

    /**
     * Procesa un nuevo escaneo: guarda en Room e intenta enviar.
     * Si ya fue enviado antes, re-envia para registrar en historial del servidor.
     */
    suspend fun procesarEscaneo(url: String, nroOrden: String? = null): EnvioResult {
        val yaEnviado = dao.yaEnviado(url) > 0

        if (yaEnviado) {
            // Ya existe en Room como enviado -> re-escaneo
            Log.d(TAG, "Re-escaneo de URL ya enviada: $url")

            if (!hayConexion()) {
                Log.d(TAG, "Sin conexion - no se puede registrar re-escaneo")
                return EnvioResult.Error("Sin conexion para registrar re-escaneo")
            }

            // Enviar al servidor (registrara en historial_escaneos)
            return intentarReEscaneo(url, nroOrden)
        }

        // Verificar si existe pero pendiente (aun no enviado)
        val existePendiente = dao.existeUrl(url) > 0
        if (existePendiente) {
            Log.d(TAG, "URL pendiente de envio: $url")
            return EnvioResult.Duplicado
        }

        // Nuevo escaneo: guardar en Room
        val entity = EscaneoEntity(url = url, nroOrden = nroOrden)
        val id = dao.insertar(entity).toInt()
        Log.d(TAG, "Escaneo guardado en Room con id=$id")

        // Intentar enviar si hay conexion
        if (!hayConexion()) {
            Log.d(TAG, "Sin conexion - guardado para sync posterior")
            return EnvioResult.GuardadoOffline
        }

        return intentarEnvio(id, url, nroOrden)
    }

    /**
     * Envia re-escaneo al servidor para registrar en historial.
     * No guarda en Room (ya existe).
     */
    private suspend fun intentarReEscaneo(url: String, nroOrden: String? = null): EnvioResult {
        return try {
            Log.d(TAG, ">>> Re-escaneo enviando a backend: url=$url nroOrden=$nroOrden")
            val response = api.enviarEscaneo(EscaneoRequest(url = url, nro_orden = nroOrden))
            val body = response.body()

            if (response.isSuccessful && body?.success == true) {
                val msg = body.message
                Log.d(TAG, "Re-escaneo registrado OK: $msg")
                EnvioResult.ReEscaneado(msg)
            } else {
                val msg = body?.message ?: "Error al registrar re-escaneo"
                Log.w(TAG, "Re-escaneo rechazado: $msg")
                EnvioResult.Error(msg)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error en re-escaneo: ${e.message}")
            EnvioResult.Error("Error de red: ${e.localizedMessage}")
        }
    }

    /**
     * Intenta enviar un escaneo al backend.
     */
    private suspend fun intentarEnvio(id: Int, url: String, nroOrden: String? = null): EnvioResult {
        return try {
            Log.d(TAG, ">>> Enviando a backend: id=$id url=$url nroOrden=$nroOrden")
            val response = api.enviarEscaneo(EscaneoRequest(url = url, nro_orden = nroOrden))
            val code = response.code()
            val body = response.body()
            val errorBody = response.errorBody()?.string()

            Log.d(TAG, "<<< Respuesta: HTTP $code, success=${body?.success}, msg=${body?.message}, errorBody=$errorBody")

            if (response.isSuccessful && body?.success == true) {
                dao.marcarEnviado(id)
                Log.d(TAG, "Escaneo enviado OK: id=$id")
                EnvioResult.Enviado
            } else if (response.isSuccessful) {
                // HTTP 200 pero success=false en el JSON
                val msg = body?.message ?: errorBody ?: "Rechazado por el servidor"
                Log.w(TAG, "Servidor rechazo: $msg para id=$id")
                dao.incrementarIntentos(id)
                EnvioResult.Error(msg)
            } else {
                dao.incrementarIntentos(id)
                val msg = "Error HTTP $code"
                Log.w(TAG, "$msg para id=$id")
                EnvioResult.Error(msg)
            }
        } catch (e: java.net.UnknownHostException) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "DNS error para id=$id: ${e.message}")
            EnvioResult.Error("No se encontro el servidor")
        } catch (e: java.net.SocketTimeoutException) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "Timeout para id=$id: ${e.message}")
            EnvioResult.Error("Timeout de conexion")
        } catch (e: javax.net.ssl.SSLException) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "SSL error para id=$id: ${e.message}")
            EnvioResult.Error("Error de seguridad SSL")
        } catch (e: java.io.IOException) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "IO error para id=$id: ${e.javaClass.simpleName} - ${e.message}")
            EnvioResult.Error("Error de red: ${e.localizedMessage}")
        } catch (e: Exception) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "Error inesperado para id=$id: ${e.javaClass.simpleName} - ${e.message}", e)
            EnvioResult.Error("Error: ${e.localizedMessage}")
        }
    }

    /**
     * Envia un control periodico al backend.
     */
    suspend fun enviarControlPeriodico(
        url: String,
        estadoCarga: String,
        chapaBaliza: String,
        comentario: String?
    ): EnvioResult {
        if (!hayConexion()) {
            return EnvioResult.Error("Sin conexion para enviar control periodico")
        }

        return try {
            Log.d(TAG, ">>> Enviando control periodico: url=$url estado=$estadoCarga chapa=$chapaBaliza")
            val request = ControlPeriodicoRequest(
                url = url,
                estado_carga = estadoCarga,
                chapa_baliza = chapaBaliza,
                comentario = comentario
            )
            val response = api.enviarControlPeriodico(request)
            val body = response.body()

            if (response.isSuccessful && body?.success == true) {
                Log.d(TAG, "Control periodico registrado OK: ${body.message}")
                EnvioResult.ReEscaneado(body.message)
            } else {
                val msg = body?.message ?: "Error al registrar control periodico"
                Log.w(TAG, "Control periodico rechazado: $msg")
                EnvioResult.Error(msg)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error en control periodico: ${e.message}")
            EnvioResult.Error("Error de red: ${e.localizedMessage}")
        }
    }

    /**
     * Resultado de sincronizacion masiva.
     */
    data class SyncResult(val enviados: Int, val fallidos: Int, val ultimoError: String = "")

    /**
     * Sincroniza todos los escaneos pendientes.
     */
    suspend fun sincronizarPendientes(): SyncResult {
        val pendientes = dao.obtenerPendientes()
        if (pendientes.isEmpty()) return SyncResult(0, 0)

        var enviados = 0
        var fallidos = 0
        var ultimoError = ""

        Log.d(TAG, "=== SYNC: ${pendientes.size} pendientes ===")

        for (escaneo in pendientes) {
            Log.d(TAG, "Sync intentando id=${escaneo.id} url=${escaneo.url} nroOrden=${escaneo.nroOrden}")
            try {
                val response = api.enviarEscaneo(EscaneoRequest(url = escaneo.url, nro_orden = escaneo.nroOrden))
                val code = response.code()
                val body = response.body()
                val errorBody = response.errorBody()?.string()

                Log.d(TAG, "Sync respuesta id=${escaneo.id}: HTTP $code, success=${body?.success}, msg=${body?.message}, error=$errorBody")

                if (response.isSuccessful && body?.success == true) {
                    dao.marcarEnviado(escaneo.id)
                    enviados++
                    Log.d(TAG, "Sync OK: id=${escaneo.id}")
                } else {
                    dao.incrementarIntentos(escaneo.id)
                    fallidos++
                    ultimoError = body?.message ?: errorBody ?: "HTTP $code"
                    Log.w(TAG, "Sync fallo id=${escaneo.id}: $ultimoError")
                }
            } catch (e: Exception) {
                dao.incrementarIntentos(escaneo.id)
                fallidos++
                ultimoError = "${e.javaClass.simpleName}: ${e.message}"
                Log.e(TAG, "Sync exception id=${escaneo.id}: $ultimoError")
            }
        }

        Log.d(TAG, "=== SYNC COMPLETO: $enviados OK, $fallidos fallidos, error='$ultimoError' ===")
        return SyncResult(enviados, fallidos, ultimoError)
    }

    /**
     * Cantidad de escaneos pendientes de envio.
     */
    suspend fun contarPendientes(): Int = dao.contarPendientes()

    /**
     * Cantidad de escaneos enviados exitosamente.
     */
    suspend fun contarEnviados(): Int = dao.contarEnviados()

    /**
     * Total de escaneos (pendientes + enviados + error).
     */
    suspend fun contarTotal(): Int = dao.contarTotal()

    /**
     * Limpia todo el historial local de escaneos.
     * Permite volver a escanear QRs que ya fueron enviados.
     */
    suspend fun limpiarHistorial() {
        dao.limpiarTodo()
        Log.d(TAG, "Historial local limpiado")
    }

    /**
     * Verifica si hay conexion a internet.
     */
    fun hayConexion(): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }
}
