package com.hst.appescaneomatafuegos

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import android.util.Log
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.google.android.material.snackbar.Snackbar
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import com.hst.appescaneomatafuegos.data.AppDatabase
import com.hst.appescaneomatafuegos.data.EscaneoRepository
import com.hst.appescaneomatafuegos.databinding.ActivityMainBinding
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/**
 * MainActivity — Pantalla principal con preview de cámara y escaneo QR en tiempo real.
 *
 * Flujo:
 * 1. Solicita permiso de cámara
 * 2. Inicia CameraX con Preview + ImageAnalysis
 * 3. ML Kit escanea cada frame buscando QR
 * 4. Si el QR contiene URL válida de AGC matafuegos:
 *    - Guarda en Room
 *    - Intenta enviar al backend
 *    - Feedback visual + vibración
 * 5. Botón sync para enviar pendientes manualmente
 * 6. WorkManager para sync automático cada 15 min
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var cameraExecutor: ExecutorService
    private lateinit var repository: EscaneoRepository

    // Palabras clave que identifican una URL de matafuegos AGC
    // Usamos contains() para ser flexibles con prefijos, espacios, etc.
    private val URL_KEYWORDS = listOf(
        "agcontrol.gob.ar/matafuegos",
        "agcontrol.gob.ar\\matafuegos",   // por si viene con backslash
        "datosestampilla.jsp"              // la página específica de estampilla
    )

    // Evitar procesamiento duplicado en tiempo real
    private var lastProcessedUrl: String = ""
    private var isProcessing: Boolean = false

    // Job para hint de "no detecta QR"
    private var hintJob: Job? = null
    private var lastDetectionTime: Long = 0

    // Contador de escaneos de la sesión
    private var scanCount: Int = 0

    // Request de permiso de cámara
    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { isGranted ->
        if (isGranted) {
            startCamera()
        } else {
            mostrarDialogoPermisosDenegados()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        cameraExecutor = Executors.newSingleThreadExecutor()

        // Inicializar repository
        val db = AppDatabase.getInstance(this)
        val api = ApiService.create()
        repository = EscaneoRepository(db.escaneoDao(), api, this)

        // Configurar botón sync
        binding.fabSync.setOnClickListener { sincronizarManual() }

        // Verificar permisos
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED
        ) {
            startCamera()
        } else {
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
        }

        // Programar WorkManager para sync automático
        programarSyncAutomatico()

        // Actualizar badge de pendientes
        actualizarBadgePendientes()

        // Iniciar hint timer
        iniciarHintTimer()
    }

    /**
     * Inicia CameraX con Preview + análisis de imagen para QR.
     */
    private fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(this)

        cameraProviderFuture.addListener({
            val cameraProvider = cameraProviderFuture.get()

            // Preview
            val preview = Preview.Builder()
                .build()
                .also {
                    it.setSurfaceProvider(binding.previewView.surfaceProvider)
                }

            // Análisis de imagen con ML Kit
            val imageAnalysis = ImageAnalysis.Builder()
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .build()
                .also {
                    it.setAnalyzer(cameraExecutor) { imageProxy ->
                        processImage(imageProxy)
                    }
                }

            // Cámara trasera
            val cameraSelector = CameraSelector.DEFAULT_BACK_CAMERA

            try {
                cameraProvider.unbindAll()
                cameraProvider.bindToLifecycle(
                    this,
                    cameraSelector,
                    preview,
                    imageAnalysis
                )
                Log.d(TAG, "Cámara iniciada correctamente")
            } catch (e: Exception) {
                Log.e(TAG, "Error al iniciar cámara: ${e.message}", e)
                showSnackbar("Error al iniciar cámara", error = true)
            }
        }, ContextCompat.getMainExecutor(this))
    }

    /**
     * Procesa cada frame de la cámara con ML Kit Barcode Scanner.
     */
    @androidx.camera.core.ExperimentalGetImage
    private fun processImage(imageProxy: ImageProxy) {
        val mediaImage = imageProxy.image
        if (mediaImage == null || isProcessing) {
            imageProxy.close()
            return
        }

        val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
        val scanner = BarcodeScanning.getClient()

        scanner.process(image)
            .addOnSuccessListener { barcodes ->
                for (barcode in barcodes) {
                    // Actualizar timestamp de detección para hint
                    lastDetectionTime = System.currentTimeMillis()

                    // Aceptar CUALQUIER QR — no filtrar por valueType
                    // ML Kit puede clasificar URLs como TYPE_URL, TYPE_TEXT u otro
                    val rawValue = barcode.rawValue ?: continue
                    val displayValue = barcode.displayValue ?: "(null)"
                    Log.d(TAG, "QR detectado: type=${barcode.valueType}, format=${barcode.format}")
                    Log.d(TAG, "  rawValue='$rawValue'")
                    Log.d(TAG, "  displayValue='$displayValue'")
                    Log.d(TAG, "  url=${barcode.url?.url ?: "(null)"}")
                    handleDetectedQR(rawValue)
                }
            }
            .addOnFailureListener { e ->
                Log.e(TAG, "Error en escaneo: ${e.message}")
            }
            .addOnCompleteListener {
                imageProxy.close()
            }
    }

    /**
     * Verifica si una URL corresponde a matafuegos AGC.
     * Usa contains() para ser flexible con prefijos, espacios, caracteres invisibles, etc.
     */
    private fun esUrlMatafuegos(url: String): Boolean {
        val cleaned = url.trim().lowercase()
        Log.d(TAG, "Validando URL: '$cleaned' (len=${cleaned.length}, bytes=${cleaned.toByteArray().joinToString(",") { it.toString() }})")
        return URL_KEYWORDS.any { keyword -> cleaned.contains(keyword.lowercase()) }
    }

    /**
     * Extrae y normaliza la URL de matafuegos del rawValue del QR.
     * Busca la URL dentro del texto y la limpia.
     */
    private fun normalizarUrl(rawValue: String): String {
        val cleaned = rawValue.trim()

        // Buscar URL dentro del texto (acepta puerto opcional como :80 o :443)
        val urlRegex = Regex("""(https?://[^\s]*agcontrol\.gob\.ar(?::\d+)?/matafuegos/[^\s]*)""", RegexOption.IGNORE_CASE)
        val match = urlRegex.find(cleaned)
        if (match != null) {
            var url = match.value
            // Quitar puertos redundantes (:80 para http, :443 para https)
            url = url.replace(Regex("""(\.ar):80/"""), "$1/")
            url = url.replace(Regex("""(\.ar):443/"""), "$1/")
            // Forzar https
            return if (url.startsWith("http://")) {
                url.replaceFirst("http://", "https://")
            } else {
                url
            }
        }

        // Si no matcheó regex pero contiene el dominio, intentar armar la URL
        val domainRegex = Regex("""(dghpsh\.agcontrol\.gob\.ar(?::\d+)?/matafuegos/[^\s]*)""", RegexOption.IGNORE_CASE)
        val domainMatch = domainRegex.find(cleaned)
        if (domainMatch != null) {
            var url = domainMatch.value
            url = url.replace(Regex("""(\.ar):80/"""), "$1/")
            url = url.replace(Regex("""(\.ar):443/"""), "$1/")
            return "https://$url"
        }

        // Fallback: limpiar y poner https si no tiene protocolo
        return when {
            cleaned.startsWith("https://") -> cleaned
            cleaned.startsWith("http://") -> cleaned.replaceFirst("http://", "https://")
            cleaned.contains("agcontrol.gob.ar") -> "https://$cleaned"
            else -> cleaned
        }
    }

    /**
     * Maneja un QR detectado: valida la URL, guarda en Room, envía al backend.
     */
    private fun handleDetectedQR(rawValue: String) {
        // Limpiar el valor crudo (trim whitespace y caracteres de control)
        val cleanedValue = rawValue.trim().replace(Regex("[\\x00-\\x1F\\x7F]"), "")

        Log.d(TAG, "handleDetectedQR raw='$rawValue' cleaned='$cleanedValue'")

        // Verificar si es URL válida de matafuegos
        if (!esUrlMatafuegos(cleanedValue)) {
            runOnUiThread {
                binding.textViewStatus.text = "QR detectado (no es de matafuegos)"
                binding.textViewResult.text = cleanedValue.take(200)
                binding.textViewResult.setTextColor(
                    ContextCompat.getColor(this, R.color.error)
                )
            }
            return
        }

        // Normalizar URL
        val url = normalizarUrl(cleanedValue)

        // Evitar procesamiento duplicado en la misma sesión
        if (url == lastProcessedUrl) return
        lastProcessedUrl = url

        Log.d(TAG, "✅ QR válido de matafuegos: $url")

        // Vibrar al detectar QR válido
        vibrar()

        // Mostrar loading
        runOnUiThread {
            binding.textViewStatus.text = "⏳ Procesando..."
            binding.textViewResult.text = url
            binding.textViewResult.setTextColor(
                ContextCompat.getColor(this, R.color.white)
            )
            binding.progressBar.visibility = View.VISIBLE
        }

        // Procesar en coroutine
        isProcessing = true
        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) {
                repository.procesarEscaneo(url)
            }

            binding.progressBar.visibility = View.GONE
            isProcessing = false

            when (result) {
                is EscaneoRepository.EnvioResult.Enviado -> {
                    scanCount++
                    binding.textContador.text = "$scanCount escaneos"
                    binding.textViewStatus.text = "✅ Enviado al servidor"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.success)
                    )
                    showSnackbar("Escaneo enviado ✓")
                }
                is EscaneoRepository.EnvioResult.GuardadoOffline -> {
                    scanCount++
                    binding.textContador.text = "$scanCount escaneos"
                    binding.textViewStatus.text = "📱 Guardado offline (sin conexión)"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.warning)
                    )
                    showSnackbar("Sin conexión — guardado para enviar después", warning = true)
                }
                is EscaneoRepository.EnvioResult.Duplicado -> {
                    binding.textViewStatus.text = "ℹ️ Ya escaneado previamente"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.primary)
                    )
                    showSnackbar("Este QR ya fue enviado")
                }
                is EscaneoRepository.EnvioResult.Error -> {
                    binding.textViewStatus.text = "⚠️ ${result.mensaje}"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.error)
                    )
                    showSnackbar(result.mensaje, error = true)
                }
            }

            actualizarBadgePendientes()
        }
    }

    /**
     * Sincronización manual al presionar el FAB.
     */
    private fun sincronizarManual() {
        if (!repository.hayConexion()) {
            showSnackbar("Sin conexión a internet", error = true)
            return
        }

        binding.fabSync.isEnabled = false
        binding.progressBar.visibility = View.VISIBLE

        lifecycleScope.launch {
            val pendientes = withContext(Dispatchers.IO) { repository.contarPendientes() }

            if (pendientes == 0) {
                binding.progressBar.visibility = View.GONE
                binding.fabSync.isEnabled = true
                showSnackbar("No hay escaneos pendientes")
                return@launch
            }

            showSnackbar("Sincronizando $pendientes escaneos...")

            val result = withContext(Dispatchers.IO) {
                repository.sincronizarPendientes()
            }

            binding.progressBar.visibility = View.GONE
            binding.fabSync.isEnabled = true

            val msg = when {
                result.fallidos == 0 -> "✅ ${result.enviados} sincronizados"
                result.enviados == 0 -> "❌ ${result.ultimoError}"
                else -> "${result.enviados} OK, ${result.fallidos} fallidos"
            }
            showSnackbar(msg, error = result.fallidos > 0 && result.enviados == 0)

            actualizarBadgePendientes()
        }
    }

    /**
     * Actualiza el badge del FAB con la cantidad de pendientes.
     */
    private fun actualizarBadgePendientes() {
        lifecycleScope.launch {
            val pendientes = withContext(Dispatchers.IO) { repository.contarPendientes() }
            binding.textPendientes.text = if (pendientes > 0) "$pendientes" else ""
            binding.textPendientes.visibility = if (pendientes > 0) View.VISIBLE else View.GONE
        }
    }

    /**
     * Programa WorkManager para sync automático cada 15 min con conexión.
     */
    private fun programarSyncAutomatico() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val syncRequest = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            SyncWorker.WORK_NAME,
            ExistingPeriodicWorkPolicy.KEEP,
            syncRequest
        )

        Log.d(TAG, "WorkManager sync automático programado")
    }

    /**
     * Vibración corta al detectar QR válido.
     */
    private fun vibrar() {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val vibratorManager = getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
                vibratorManager.defaultVibrator.vibrate(
                    VibrationEffect.createOneShot(150, VibrationEffect.DEFAULT_AMPLITUDE)
                )
            } else {
                @Suppress("DEPRECATION")
                val vibrator = getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    vibrator.vibrate(
                        VibrationEffect.createOneShot(150, VibrationEffect.DEFAULT_AMPLITUDE)
                    )
                } else {
                    @Suppress("DEPRECATION")
                    vibrator.vibrate(150)
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "No se pudo vibrar: ${e.message}")
        }
    }

    /**
     * Inicia timer que muestra hint si no se detecta QR por 5 segundos.
     */
    private fun iniciarHintTimer() {
        lastDetectionTime = System.currentTimeMillis()
        hintJob = lifecycleScope.launch {
            while (true) {
                delay(5000)
                val elapsed = System.currentTimeMillis() - lastDetectionTime
                if (elapsed > 5000 && !isProcessing) {
                    binding.textViewStatus.text = "💡 Apuntá al código QR de la tarjeta del matafuegos"
                }
            }
        }
    }

    /**
     * Muestra Snackbar con mensaje.
     */
    private fun showSnackbar(message: String, error: Boolean = false, warning: Boolean = false) {
        val snackbar = Snackbar.make(binding.root, message, Snackbar.LENGTH_SHORT)
        when {
            error -> snackbar.setBackgroundTint(ContextCompat.getColor(this, R.color.error))
            warning -> snackbar.setBackgroundTint(ContextCompat.getColor(this, R.color.warning))
        }
        snackbar.show()
    }

    /**
     * Diálogo cuando se deniegan permisos de cámara.
     */
    private fun mostrarDialogoPermisosDenegados() {
        AlertDialog.Builder(this)
            .setTitle("Permiso de cámara necesario")
            .setMessage("Esta app necesita acceso a la cámara para escanear códigos QR de matafuegos. Por favor habilitá el permiso en Configuración.")
            .setPositiveButton("Ir a Configuración") { _, _ ->
                val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                    data = Uri.fromParts("package", packageName, null)
                }
                startActivity(intent)
            }
            .setNegativeButton("Cerrar") { _, _ -> finish() }
            .setCancelable(false)
            .show()
    }

    override fun onResume() {
        super.onResume()
        // Permitir re-escanear el mismo QR si vuelve a la app
        lastProcessedUrl = ""
        actualizarBadgePendientes()
    }

    override fun onDestroy() {
        super.onDestroy()
        hintJob?.cancel()
        cameraExecutor.shutdown()
    }

    companion object {
        private const val TAG = "EscaneoMatafuegos"
    }
}
