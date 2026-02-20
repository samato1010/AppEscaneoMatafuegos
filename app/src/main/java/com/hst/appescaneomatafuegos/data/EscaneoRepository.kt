package com.hst.appescaneomatafuegos.data

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.util.Log
import com.hst.appescaneomatafuegos.ApiService
import com.hst.appescaneomatafuegos.EscaneoRequest

/**
 * Repository que combina Room (offline) + Retrofit (online).
 *
 * Flujo:
 * 1. Al escanear QR → guardar en Room como "pendiente"
 * 2. Si hay conexión → enviar al backend → marcar "enviado"
 * 3. Si falla → queda "pendiente" para sync posterior
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
     * Resultado de un intento de envío.
     */
    sealed class EnvioResult {
        data object Enviado : EnvioResult()
        data object GuardadoOffline : EnvioResult()
        data object Duplicado : EnvioResult()
        data class Error(val mensaje: String) : EnvioResult()
    }

    /**
     * Procesa un nuevo escaneo: guarda en Room e intenta enviar.
     */
    suspend fun procesarEscaneo(url: String): EnvioResult {
        // Verificar duplicados
        if (dao.yaEnviado(url) > 0) {
            Log.d(TAG, "URL ya enviada previamente: $url")
            return EnvioResult.Duplicado
        }

        // Guardar en Room
        val entity = EscaneoEntity(url = url)
        val id = dao.insertar(entity).toInt()
        Log.d(TAG, "Escaneo guardado en Room con id=$id")

        // Intentar enviar si hay conexión
        if (!hayConexion()) {
            Log.d(TAG, "Sin conexión - guardado para sync posterior")
            return EnvioResult.GuardadoOffline
        }

        return intentarEnvio(id, url)
    }

    /**
     * Intenta enviar un escaneo al backend.
     */
    private suspend fun intentarEnvio(id: Int, url: String): EnvioResult {
        return try {
            Log.d(TAG, ">>> Enviando a backend: id=$id url=$url")
            val response = api.enviarEscaneo(EscaneoRequest(url = url))
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
                Log.w(TAG, "Servidor rechazó: $msg para id=$id")
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
            EnvioResult.Error("No se encontró el servidor")
        } catch (e: java.net.SocketTimeoutException) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "Timeout para id=$id: ${e.message}")
            EnvioResult.Error("Timeout de conexión")
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
     * Sincroniza todos los escaneos pendientes.
     * Retorna: Pair(enviados, fallidos)
     */
    suspend fun sincronizarPendientes(): Pair<Int, Int> {
        val pendientes = dao.obtenerPendientes()
        if (pendientes.isEmpty()) return Pair(0, 0)

        var enviados = 0
        var fallidos = 0

        for (escaneo in pendientes) {
            try {
                val response = api.enviarEscaneo(EscaneoRequest(url = escaneo.url))
                if (response.isSuccessful) {
                    dao.marcarEnviado(escaneo.id)
                    enviados++
                    Log.d(TAG, "Sync OK: id=${escaneo.id}")
                } else {
                    dao.incrementarIntentos(escaneo.id)
                    fallidos++
                    Log.w(TAG, "Sync error ${response.code()}: id=${escaneo.id}")
                }
            } catch (e: Exception) {
                dao.incrementarIntentos(escaneo.id)
                fallidos++
                Log.e(TAG, "Sync exception: id=${escaneo.id} - ${e.message}")
            }
        }

        return Pair(enviados, fallidos)
    }

    /**
     * Cantidad de escaneos pendientes de envío.
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
     * Verifica si hay conexión a internet.
     */
    fun hayConexion(): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }
}
