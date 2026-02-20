package com.hst.appescaneomatafuegos

import android.util.Log
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.Body
import retrofit2.http.POST
import java.util.concurrent.TimeUnit

/**
 * Modelo de datos para enviar escaneo al backend.
 */
data class EscaneoRequest(
    val url: String,
    val nro_orden: String? = null
)

/**
 * Respuesta del backend PHP.
 */
data class EscaneoResponse(
    val success: Boolean = false,
    val message: String = ""
)

/**
 * Request para control periódico.
 */
data class ControlPeriodicoRequest(
    val url: String,
    val estado_carga: String,
    val chapa_baliza: String,
    val comentario: String? = null
)

/**
 * Respuesta del backend para control periódico.
 */
data class ControlPeriodicoResponse(
    val success: Boolean = false,
    val message: String = "",
    val total_controles: Int = 0
)

/**
 * Interface Retrofit para comunicación con el backend Hostinger.
 */
interface ApiService {

    @POST("recibir_escaneo.php")
    suspend fun enviarEscaneo(@Body request: EscaneoRequest): Response<EscaneoResponse>

    @POST("recibir_control_periodico.php")
    suspend fun enviarControlPeriodico(@Body request: ControlPeriodicoRequest): Response<ControlPeriodicoResponse>

    companion object {
        private const val TAG = "ApiService"
        private const val BASE_URL = "https://hst.ar/belga/"

        @Volatile
        private var instance: ApiService? = null

        /**
         * Crea instancia singleton de ApiService con timeouts y logging.
         */
        fun create(): ApiService {
            return instance ?: synchronized(this) {
                instance ?: buildApi().also { instance = it }
            }
        }

        private fun buildApi(): ApiService {
            val logging = HttpLoggingInterceptor { message ->
                Log.d(TAG, message)
            }.apply {
                level = HttpLoggingInterceptor.Level.BODY
            }

            val client = OkHttpClient.Builder()
                .connectTimeout(30, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .writeTimeout(30, TimeUnit.SECONDS)
                .followRedirects(true)
                .followSslRedirects(true)
                .addInterceptor(logging)
                .build()

            return Retrofit.Builder()
                .baseUrl(BASE_URL)
                .client(client)
                .addConverterFactory(GsonConverterFactory.create())
                .build()
                .create(ApiService::class.java)
        }
    }
}
