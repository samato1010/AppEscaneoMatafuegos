package com.hst.appescaneomatafuegos

import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.Body
import retrofit2.http.POST

/**
 * Modelo de datos para enviar al backend.
 * Solo contiene la URL extraída del QR.
 */
data class EscaneoRequest(
    val url: String
)

/**
 * Respuesta genérica del backend.
 */
data class EscaneoResponse(
    val success: Boolean = false,
    val message: String = ""
)

/**
 * Interface Retrofit para comunicación con el backend.
 */
interface ApiService {

    @POST("api/escaneos")
    suspend fun enviarEscaneo(@Body request: EscaneoRequest): Response<EscaneoResponse>

    companion object {
        // TODO: Cambiar por la URL real del backend
        private const val BASE_URL = "https://tu-dominio.com/"

        /**
         * Crea instancia singleton de ApiService.
         */
        fun create(): ApiService {
            return Retrofit.Builder()
                .baseUrl(BASE_URL)
                .addConverterFactory(GsonConverterFactory.create())
                .build()
                .create(ApiService::class.java)
        }
    }
}
