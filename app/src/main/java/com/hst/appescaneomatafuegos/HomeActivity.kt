package com.hst.appescaneomatafuegos

import android.content.Intent
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.snackbar.Snackbar
import com.hst.appescaneomatafuegos.data.AppDatabase
import com.hst.appescaneomatafuegos.data.EscaneoRepository
import com.hst.appescaneomatafuegos.databinding.ActivityHomeBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * HomeActivity — Pantalla de inicio.
 *
 * Muestra:
 * - Estado de sincronización (Sincronizado / Guardado pendiente)
 * - Cantidad de extintores enviados, pendientes y total
 * - Botón para sincronizar pendientes
 * - Botón principal para abrir el escáner
 */
class HomeActivity : AppCompatActivity() {

    private lateinit var binding: ActivityHomeBinding
    private lateinit var repository: EscaneoRepository

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityHomeBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Inicializar repository
        val db = AppDatabase.getInstance(this)
        val api = ApiService.create()
        repository = EscaneoRepository(db.escaneoDao(), api, this)

        // Botón ESCANEAR EXTINTOR → abre cámara
        binding.btnEscanear.setOnClickListener {
            startActivity(Intent(this, MainActivity::class.java))
        }

        // Botón SINCRONIZAR
        binding.btnSincronizar.setOnClickListener {
            sincronizarPendientes()
        }
    }

    override fun onResume() {
        super.onResume()
        actualizarEstado()
    }

    /**
     * Actualiza todos los contadores y el estado visual.
     */
    private fun actualizarEstado() {
        lifecycleScope.launch {
            val pendientes = withContext(Dispatchers.IO) { repository.contarPendientes() }
            val enviados = withContext(Dispatchers.IO) { repository.contarEnviados() }
            val total = withContext(Dispatchers.IO) { repository.contarTotal() }

            // Actualizar contadores
            binding.textEnviados.text = "$enviados"
            binding.textGuardados.text = "$pendientes"
            binding.textTotal.text = "$total"

            // Estado de sincronización
            if (pendientes > 0) {
                // Hay pendientes → estado "Guardado"
                binding.textEstado.text = "Guardado ($pendientes pendientes)"
                binding.textEstado.setTextColor(ContextCompat.getColor(this@HomeActivity, R.color.warning))

                // Indicador naranja
                val indicator = binding.indicadorEstado.background
                if (indicator is GradientDrawable) {
                    indicator.setColor(ContextCompat.getColor(this@HomeActivity, R.color.warning))
                }

                // Mostrar botón sincronizar
                binding.btnSincronizar.visibility = View.VISIBLE
                binding.btnSincronizar.text = "SINCRONIZAR $pendientes PENDIENTES"
            } else {
                // Todo sincronizado
                binding.textEstado.text = "Sincronizado"
                binding.textEstado.setTextColor(ContextCompat.getColor(this@HomeActivity, R.color.success))

                // Indicador verde
                val indicator = binding.indicadorEstado.background
                if (indicator is GradientDrawable) {
                    indicator.setColor(ContextCompat.getColor(this@HomeActivity, R.color.success))
                }

                // Ocultar botón sincronizar
                binding.btnSincronizar.visibility = View.GONE
            }
        }
    }

    /**
     * Sincroniza los escaneos pendientes con el backend.
     */
    private fun sincronizarPendientes() {
        if (!repository.hayConexion()) {
            Snackbar.make(binding.root, "Sin conexión a internet", Snackbar.LENGTH_SHORT)
                .setBackgroundTint(ContextCompat.getColor(this, R.color.error))
                .show()
            return
        }

        binding.btnSincronizar.isEnabled = false
        binding.progressSync.visibility = View.VISIBLE

        lifecycleScope.launch {
            val (enviados, fallidos) = withContext(Dispatchers.IO) {
                repository.sincronizarPendientes()
            }

            binding.progressSync.visibility = View.GONE
            binding.btnSincronizar.isEnabled = true

            val msg = when {
                fallidos == 0 && enviados > 0 -> "✅ $enviados extintores sincronizados"
                enviados == 0 && fallidos > 0 -> "❌ No se pudo sincronizar ($fallidos fallidos)"
                enviados > 0 && fallidos > 0 -> "Sincronizados $enviados de ${enviados + fallidos}"
                else -> "No hay pendientes"
            }

            val isError = fallidos > 0 && enviados == 0
            Snackbar.make(binding.root, msg, Snackbar.LENGTH_SHORT).apply {
                if (isError) setBackgroundTint(ContextCompat.getColor(this@HomeActivity, R.color.error))
            }.show()

            // Refrescar estado
            actualizarEstado()
        }
    }
}
