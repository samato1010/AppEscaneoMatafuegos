package com.hst.appescaneomatafuegos

import okhttp3.OkHttpClient
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.Body
import retrofit2.http.POST
import java.util.concurrent.TimeUnit

/**
 * Modelo de datos para enviar al backend.
 */
data class EscaneoRequest(
    val url: String
)

/**
 * Respuesta del backend PHP.
 */
data class EscaneoResponse(
    val success: Boolean = false,
    val message: String = ""
)

/**
 * Interface Retrofit para comunicación con el backend Hostinger.
 */
interface ApiService {

    @POST("recibir_escaneo.php")
    suspend fun enviarEscaneo(@Body request: EscaneoRequest): Response<EscaneoResponse>

    companion object {
        private const val BASE_URL = "https://hst.ar/belga/"

        /**
         * Crea instancia singleton de ApiService con timeouts configurados.
         */
        fun create(): ApiService {
            val client = OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(15, TimeUnit.SECONDS)
                .writeTimeout(15, TimeUnit.SECONDS)
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
