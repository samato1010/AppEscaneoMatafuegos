package com.hst.appescaneomatafuegos

import android.content.Intent
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.view.View
import android.widget.EditText
import androidx.appcompat.app.AlertDialog
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
 * - Estado de sincronizacion (Sincronizado / Guardado pendiente)
 * - Cantidad de extintores enviados, pendientes y total
 * - Boton para sincronizar pendientes
 * - Dos botones principales: Escanear para Orden / Control Periodico
 */
class HomeActivity : AppCompatActivity() {

    private lateinit var binding: ActivityHomeBinding
    private lateinit var repository: EscaneoRepository

    companion object {
        const val EXTRA_SCAN_MODE = "SCAN_MODE"
        const val EXTRA_NRO_ORDEN = "NRO_ORDEN"
        const val MODE_ORDEN = "orden"
        const val MODE_CONTROL = "control_periodico"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityHomeBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Inicializar repository
        val db = AppDatabase.getInstance(this)
        val api = ApiService.create()
        repository = EscaneoRepository(db.escaneoDao(), api, this)

        // Boton ESCANEAR PARA ORDEN -> pide nro de orden, luego abre camara
        binding.btnEscanearOrden.setOnClickListener {
            mostrarDialogoNroOrden()
        }

        // Boton CONTROL PERIODICO -> abre camara directamente en modo control
        binding.btnEscanearControl.setOnClickListener {
            val intent = Intent(this, MainActivity::class.java).apply {
                putExtra(EXTRA_SCAN_MODE, MODE_CONTROL)
            }
            startActivity(intent)
        }

        // Boton SINCRONIZAR
        binding.btnSincronizar.setOnClickListener {
            sincronizarPendientes()
        }

        // Boton LIMPIAR HISTORIAL
        binding.btnLimpiar.setOnClickListener {
            confirmarLimpiarHistorial()
        }
    }

    override fun onResume() {
        super.onResume()
        actualizarEstado()
    }

    /**
     * Muestra dialogo para ingresar el numero de orden antes de escanear.
     */
    private fun mostrarDialogoNroOrden() {
        val editText = EditText(this).apply {
            hint = "Ej: ORD-001, 12345"
            setPadding(48, 32, 48, 32)
            textSize = 18f
            inputType = android.text.InputType.TYPE_CLASS_TEXT or
                    android.text.InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS
        }

        AlertDialog.Builder(this)
            .setTitle("Numero de Orden")
            .setMessage("Ingrese el numero de orden para esta sesion de escaneo:")
            .setView(editText)
            .setPositiveButton("Iniciar Escaneo") { _, _ ->
                val nroOrden = editText.text.toString().trim()
                if (nroOrden.isEmpty()) {
                    Snackbar.make(binding.root, "Debe ingresar un numero de orden", Snackbar.LENGTH_SHORT)
                        .setBackgroundTint(ContextCompat.getColor(this, R.color.error))
                        .show()
                    return@setPositiveButton
                }

                val intent = Intent(this, MainActivity::class.java).apply {
                    putExtra(EXTRA_SCAN_MODE, MODE_ORDEN)
                    putExtra(EXTRA_NRO_ORDEN, nroOrden)
                }
                startActivity(intent)
            }
            .setNegativeButton("Cancelar", null)
            .show()
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

            // Estado de sincronizacion
            if (pendientes > 0) {
                // Hay pendientes -> estado "Guardado"
                binding.textEstado.text = "Guardado ($pendientes pendientes)"
                binding.textEstado.setTextColor(ContextCompat.getColor(this@HomeActivity, R.color.warning))

                // Indicador naranja
                val indicator = binding.indicadorEstado.background
                if (indicator is GradientDrawable) {
                    indicator.setColor(ContextCompat.getColor(this@HomeActivity, R.color.warning))
                }

                // Mostrar boton sincronizar
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

                // Ocultar boton sincronizar
                binding.btnSincronizar.visibility = View.GONE
            }
        }
    }

    /**
     * Muestra dialogo de confirmacion y limpia el historial local.
     */
    private fun confirmarLimpiarHistorial() {
        AlertDialog.Builder(this)
            .setTitle("Limpiar historial")
            .setMessage("Se borraran todos los escaneos del dispositivo. Esto permite volver a escanear QRs que ya fueron enviados.\n\nLos datos del servidor no se afectan.")
            .setPositiveButton("Limpiar") { _, _ ->
                lifecycleScope.launch {
                    withContext(Dispatchers.IO) {
                        repository.limpiarHistorial()
                    }
                    Snackbar.make(binding.root, "Historial limpiado", Snackbar.LENGTH_SHORT).show()
                    actualizarEstado()
                }
            }
            .setNegativeButton("Cancelar", null)
            .show()
    }

    /**
     * Sincroniza los escaneos pendientes con el backend.
     */
    private fun sincronizarPendientes() {
        if (!repository.hayConexion()) {
            Snackbar.make(binding.root, "Sin conexion a internet", Snackbar.LENGTH_LONG)
                .setBackgroundTint(ContextCompat.getColor(this, R.color.error))
                .show()
            return
        }

        binding.btnSincronizar.isEnabled = false
        binding.progressSync.visibility = View.VISIBLE

        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) {
                repository.sincronizarPendientes()
            }

            binding.progressSync.visibility = View.GONE
            binding.btnSincronizar.isEnabled = true

            val msg = when {
                result.fallidos == 0 && result.enviados > 0 ->
                    "${result.enviados} extintores sincronizados"
                result.enviados == 0 && result.fallidos > 0 ->
                    "Error: ${result.ultimoError}"
                result.enviados > 0 && result.fallidos > 0 ->
                    "Parcial: ${result.enviados} OK, ${result.fallidos} fallidos\n${result.ultimoError}"
                else -> "No hay pendientes"
            }

            val isError = result.fallidos > 0
            Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).apply {
                if (isError) setBackgroundTint(ContextCompat.getColor(this@HomeActivity, R.color.error))
            }.show()

            // Refrescar estado
            actualizarEstado()
        }
    }
}
