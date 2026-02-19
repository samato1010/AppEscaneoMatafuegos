package com.hst.appescaneomatafuegos.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import androidx.room.Update

/**
 * DAO para operaciones de escaneos en Room.
 */
@Dao
interface EscaneoDao {

    @Insert
    suspend fun insertar(escaneo: EscaneoEntity): Long

    @Update
    suspend fun actualizar(escaneo: EscaneoEntity)

    @Query("SELECT * FROM escaneos WHERE estado = 'pendiente' ORDER BY fecha ASC")
    suspend fun obtenerPendientes(): List<EscaneoEntity>

    @Query("SELECT COUNT(*) FROM escaneos WHERE estado = 'pendiente'")
    suspend fun contarPendientes(): Int

    @Query("SELECT * FROM escaneos ORDER BY fecha DESC LIMIT 50")
    suspend fun obtenerRecientes(): List<EscaneoEntity>

    @Query("SELECT COUNT(*) FROM escaneos WHERE url = :url AND estado = 'enviado'")
    suspend fun yaEnviado(url: String): Int

    @Query("SELECT COUNT(*) FROM escaneos WHERE url = :url")
    suspend fun existeUrl(url: String): Int

    @Query("UPDATE escaneos SET estado = 'enviado' WHERE id = :id")
    suspend fun marcarEnviado(id: Int)

    @Query("UPDATE escaneos SET intentos = intentos + 1 WHERE id = :id")
    suspend fun incrementarIntentos(id: Int)
}
