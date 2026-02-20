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
            val response = api.enviarEscaneo(EscaneoRequest(url = url))

            if (response.isSuccessful) {
                dao.marcarEnviado(id)
                Log.d(TAG, "Escaneo enviado OK: id=$id")
                EnvioResult.Enviado
            } else {
                dao.incrementarIntentos(id)
                val msg = "Error servidor: ${response.code()}"
                Log.w(TAG, "$msg para id=$id")
                EnvioResult.Error(msg)
            }
        } catch (e: Exception) {
            dao.incrementarIntentos(id)
            Log.e(TAG, "Error de red para id=$id: ${e.message}", e)
            EnvioResult.Error("Error de conexión: ${e.localizedMessage}")
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
