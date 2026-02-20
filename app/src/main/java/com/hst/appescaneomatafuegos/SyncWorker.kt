package com.hst.appescaneomatafuegos

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.hst.appescaneomatafuegos.data.AppDatabase
import com.hst.appescaneomatafuegos.data.EscaneoRepository

/**
 * Worker para sincronización automática de escaneos pendientes.
 * Se ejecuta periódicamente cada 15 minutos cuando hay conexión.
 */
class SyncWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        const val TAG = "SyncWorker"
        const val WORK_NAME = "sync_escaneos_periodico"
    }

    override suspend fun doWork(): Result {
        Log.d(TAG, "Iniciando sync automático...")

        val db = AppDatabase.getInstance(applicationContext)
        val api = ApiService.create()
        val repo = EscaneoRepository(db.escaneoDao(), api, applicationContext)

        val pendientes = repo.contarPendientes()
        if (pendientes == 0) {
            Log.d(TAG, "Sin escaneos pendientes")
            return Result.success()
        }

        Log.d(TAG, "Sincronizando $pendientes escaneos pendientes...")
        val result = repo.sincronizarPendientes()

        Log.d(TAG, "Sync completado: ${result.enviados} enviados, ${result.fallidos} fallidos, error=${result.ultimoError}")

        return if (result.fallidos > 0) Result.retry() else Result.success()
    }
}
