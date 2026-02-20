package com.hst.appescaneomatafuegos.data

import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * Entidad Room para escaneos de matafuegos.
 * Persiste QR escaneados para soporte offline.
 */
@Entity(tableName = "escaneos")
data class EscaneoEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Int = 0,
    val url: String,
    val fecha: Long = System.currentTimeMillis(),
    val estado: String = "pendiente",  // "pendiente", "enviado", "error"
    val intentos: Int = 0,
    val nroOrden: String? = null
)
