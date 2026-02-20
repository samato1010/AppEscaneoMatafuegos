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
import android.view.LayoutInflater
import android.view.View
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.Spinner
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
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
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
 * MainActivity — Pantalla principal con preview de camara y escaneo QR en tiempo real.
 *
 * Soporta dos modos:
 * - ORDEN: Escanea extintores asociados a un nro de orden
 * - CONTROL PERIODICO: Despues de cada escaneo muestra formulario de control
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var cameraExecutor: ExecutorService
    private lateinit var repository: EscaneoRepository

    // Modo de escaneo
    private var scanMode: String = HomeActivity.MODE_ORDEN
    private var nroOrden: String? = null

    // Palabras clave que identifican una URL de matafuegos AGC
    private val URL_KEYWORDS = listOf(
        "agcontrol.gob.ar/matafuegos",
        "agcontrol.gob.ar\\matafuegos",
        "datosestampilla.jsp"
    )

    // Evitar procesamiento duplicado en tiempo real
    private var lastProcessedUrl: String = ""
    private var isProcessing: Boolean = false

    // Job para hint de "no detecta QR"
    private var hintJob: Job? = null
    private var lastDetectionTime: Long = 0

    // Contador de escaneos de la sesion
    private var scanCount: Int = 0

    // Request de permiso de camara
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

        // Leer modo de escaneo del intent
        scanMode = intent.getStringExtra(HomeActivity.EXTRA_SCAN_MODE) ?: HomeActivity.MODE_ORDEN
        nroOrden = intent.getStringExtra(HomeActivity.EXTRA_NRO_ORDEN)

        // Mostrar indicador de modo
        actualizarIndicadorModo()

        // Inicializar repository
        val db = AppDatabase.getInstance(this)
        val api = ApiService.create()
        repository = EscaneoRepository(db.escaneoDao(), api, this)

        // Configurar boton sync
        binding.fabSync.setOnClickListener { sincronizarManual() }

        // Verificar permisos
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED
        ) {
            startCamera()
        } else {
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
        }

        // Programar WorkManager para sync automatico
        programarSyncAutomatico()

        // Actualizar badge de pendientes
        actualizarBadgePendientes()

        // Iniciar hint timer
        iniciarHintTimer()
    }

    /**
     * Muestra el modo activo en la pantalla del scanner.
     */
    private fun actualizarIndicadorModo() {
        val modeText = when (scanMode) {
            HomeActivity.MODE_ORDEN -> "ORDEN: ${nroOrden ?: "?"}"
            HomeActivity.MODE_CONTROL -> "CONTROL PERIODICO"
            else -> ""
        }
        binding.textViewStatus.text = modeText

        // Color segun modo
        val color = when (scanMode) {
            HomeActivity.MODE_CONTROL -> ContextCompat.getColor(this, R.color.purple)
            else -> ContextCompat.getColor(this, R.color.primary)
        }
        binding.textViewStatus.setTextColor(color)
    }

    /**
     * Inicia CameraX con Preview + analisis de imagen para QR.
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

            // Analisis de imagen con ML Kit
            val imageAnalysis = ImageAnalysis.Builder()
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .build()
                .also {
                    it.setAnalyzer(cameraExecutor) { imageProxy ->
                        processImage(imageProxy)
                    }
                }

            // Camara trasera
            val cameraSelector = CameraSelector.DEFAULT_BACK_CAMERA

            try {
                cameraProvider.unbindAll()
                cameraProvider.bindToLifecycle(
                    this,
                    cameraSelector,
                    preview,
                    imageAnalysis
                )
                Log.d(TAG, "Camara iniciada correctamente")
            } catch (e: Exception) {
                Log.e(TAG, "Error al iniciar camara: ${e.message}", e)
                showSnackbar("Error al iniciar camara", error = true)
            }
        }, ContextCompat.getMainExecutor(this))
    }

    /**
     * Procesa cada frame de la camara con ML Kit Barcode Scanner.
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
                    // Actualizar timestamp de deteccion para hint
                    lastDetectionTime = System.currentTimeMillis()

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
     */
    private fun esUrlMatafuegos(url: String): Boolean {
        val cleaned = url.trim().lowercase()
        Log.d(TAG, "Validando URL: '$cleaned' (len=${cleaned.length})")
        return URL_KEYWORDS.any { keyword -> cleaned.contains(keyword.lowercase()) }
    }

    /**
     * Extrae y normaliza la URL de matafuegos del rawValue del QR.
     */
    private fun normalizarUrl(rawValue: String): String {
        val cleaned = rawValue.trim()

        val urlRegex = Regex("""(https?://[^\s]*agcontrol\.gob\.ar(?::\d+)?/matafuegos/[^\s]*)""", RegexOption.IGNORE_CASE)
        val match = urlRegex.find(cleaned)
        if (match != null) {
            var url = match.value
            url = url.replace(Regex("""(\.ar):80/"""), "$1/")
            url = url.replace(Regex("""(\.ar):443/"""), "$1/")
            return if (url.startsWith("http://")) {
                url.replaceFirst("http://", "https://")
            } else {
                url
            }
        }

        val domainRegex = Regex("""(dghpsh\.agcontrol\.gob\.ar(?::\d+)?/matafuegos/[^\s]*)""", RegexOption.IGNORE_CASE)
        val domainMatch = domainRegex.find(cleaned)
        if (domainMatch != null) {
            var url = domainMatch.value
            url = url.replace(Regex("""(\.ar):80/"""), "$1/")
            url = url.replace(Regex("""(\.ar):443/"""), "$1/")
            return "https://$url"
        }

        return when {
            cleaned.startsWith("https://") -> cleaned
            cleaned.startsWith("http://") -> cleaned.replaceFirst("http://", "https://")
            cleaned.contains("agcontrol.gob.ar") -> "https://$cleaned"
            else -> cleaned
        }
    }

    /**
     * Maneja un QR detectado: valida la URL, guarda en Room, envia al backend.
     */
    private fun handleDetectedQR(rawValue: String) {
        val cleanedValue = rawValue.trim().replace(Regex("[\\x00-\\x1F\\x7F]"), "")

        Log.d(TAG, "handleDetectedQR raw='$rawValue' cleaned='$cleanedValue'")

        // Verificar si es URL valida de matafuegos
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

        // Evitar procesamiento duplicado del mismo frame (cooldown 3s)
        if (url == lastProcessedUrl) return
        lastProcessedUrl = url

        // Resetear despues de 3s para permitir re-escaneo intencional
        lifecycleScope.launch {
            delay(3000)
            if (lastProcessedUrl == url) {
                lastProcessedUrl = ""
            }
        }

        Log.d(TAG, "QR valido de matafuegos: $url (modo=$scanMode)")

        // Vibrar al detectar QR valido
        vibrar()

        // Mostrar loading
        runOnUiThread {
            binding.textViewStatus.text = "Procesando..."
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
                repository.procesarEscaneo(url, nroOrden)
            }

            binding.progressBar.visibility = View.GONE

            when (result) {
                is EscaneoRepository.EnvioResult.Enviado -> {
                    scanCount++
                    binding.textContador.text = "$scanCount escaneos"
                    binding.textViewStatus.text = "Enviado al servidor"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.success)
                    )
                    showSnackbar("Escaneo enviado")

                    // En modo control, mostrar formulario
                    if (scanMode == HomeActivity.MODE_CONTROL) {
                        mostrarFormularioControl(url)
                    } else {
                        isProcessing = false
                    }
                }
                is EscaneoRepository.EnvioResult.GuardadoOffline -> {
                    scanCount++
                    binding.textContador.text = "$scanCount escaneos"
                    binding.textViewStatus.text = "Guardado offline (sin conexion)"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.warning)
                    )
                    showSnackbar("Sin conexion - guardado para enviar despues", warning = true)
                    isProcessing = false
                }
                is EscaneoRepository.EnvioResult.Duplicado -> {
                    binding.textViewStatus.text = "Pendiente de envio"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.primary)
                    )
                    showSnackbar("Este QR esta pendiente de envio")
                    isProcessing = false
                }
                is EscaneoRepository.EnvioResult.ReEscaneado -> {
                    scanCount++
                    binding.textContador.text = "$scanCount escaneos"
                    binding.textViewStatus.text = "Re-escaneo registrado"
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.success)
                    )
                    showSnackbar(result.mensaje)

                    // En modo control, mostrar formulario tambien para re-escaneos
                    if (scanMode == HomeActivity.MODE_CONTROL) {
                        mostrarFormularioControl(url)
                    } else {
                        isProcessing = false
                    }
                }
                is EscaneoRepository.EnvioResult.Error -> {
                    binding.textViewStatus.text = result.mensaje
                    binding.textViewResult.setTextColor(
                        ContextCompat.getColor(this@MainActivity, R.color.error)
                    )
                    showSnackbar(result.mensaje, error = true)
                    isProcessing = false
                }
            }

            actualizarBadgePendientes()
        }
    }

    /**
     * Muestra el formulario de control periodico despues de un escaneo exitoso.
     * Pausa el scanner hasta que se complete o cancele.
     */
    private fun mostrarFormularioControl(url: String) {
        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_control_periodico, null)

        // Configurar spinner estado de carga
        val spinnerEstado = dialogView.findViewById<Spinner>(R.id.spinnerEstadoCarga)
        val estadosArray = arrayOf("Cargado", "Descargado", "Sobrecargado")
        spinnerEstado.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, estadosArray)

        // Configurar spinner chapa/baliza
        val spinnerChapa = dialogView.findViewById<Spinner>(R.id.spinnerChapaBaliza)
        val chapasArray = arrayOf("A", "ABC", "BC", "No tiene", "Otra")
        spinnerChapa.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, chapasArray)

        // Mostrar/ocultar campo "Otra"
        val layoutOtraChapa = dialogView.findViewById<TextInputLayout>(R.id.layoutOtraChapa)
        val editOtraChapa = dialogView.findViewById<TextInputEditText>(R.id.editOtraChapa)

        spinnerChapa.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                if (chapasArray[position] == "Otra") {
                    layoutOtraChapa.visibility = View.VISIBLE
                    editOtraChapa.requestFocus()
                } else {
                    layoutOtraChapa.visibility = View.GONE
                }
            }
            override fun onNothingSelected(parent: AdapterView<*>?) {}
        }

        val editComentario = dialogView.findViewById<TextInputEditText>(R.id.editComentario)

        val dialog = AlertDialog.Builder(this)
            .setTitle("Control Periodico")
            .setView(dialogView)
            .setCancelable(false)
            .setPositiveButton("Guardar", null) // Se configura despues para evitar cierre automatico
            .setNegativeButton("Omitir") { _, _ ->
                // Cancelar: reanudar scanner
                isProcessing = false
                actualizarIndicadorModo()
            }
            .create()

        dialog.setOnShowListener {
            val btnGuardar = dialog.getButton(AlertDialog.BUTTON_POSITIVE)
            btnGuardar.setOnClickListener {
                val estadoCarga = estadosArray[spinnerEstado.selectedItemPosition]
                val chapaSeleccion = chapasArray[spinnerChapa.selectedItemPosition]
                val chapaBaliza = if (chapaSeleccion == "Otra") {
                    val otraTexto = editOtraChapa.text.toString().trim()
                    if (otraTexto.isEmpty()) {
                        editOtraChapa.error = "Especifique la chapa"
                        return@setOnClickListener
                    }
                    otraTexto
                } else {
                    chapaSeleccion
                }
                val comentario = editComentario.text.toString().trim().ifEmpty { null }

                // Enviar al backend
                btnGuardar.isEnabled = false
                dialog.getButton(AlertDialog.BUTTON_NEGATIVE).isEnabled = false

                lifecycleScope.launch {
                    val result = withContext(Dispatchers.IO) {
                        repository.enviarControlPeriodico(url, estadoCarga, chapaBaliza, comentario)
                    }

                    when (result) {
                        is EscaneoRepository.EnvioResult.ReEscaneado -> {
                            showSnackbar("Control registrado: ${result.mensaje}")
                        }
                        is EscaneoRepository.EnvioResult.Error -> {
                            showSnackbar("Error: ${result.mensaje}", error = true)
                        }
                        else -> {
                            showSnackbar("Control guardado")
                        }
                    }

                    dialog.dismiss()
                    isProcessing = false
                    actualizarIndicadorModo()
                }
            }
        }

        dialog.show()
    }

    /**
     * Sincronizacion manual al presionar el FAB.
     */
    private fun sincronizarManual() {
        if (!repository.hayConexion()) {
            showSnackbar("Sin conexion a internet", error = true)
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
                result.fallidos == 0 -> "${result.enviados} sincronizados"
                result.enviados == 0 -> "${result.ultimoError}"
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
     * Programa WorkManager para sync automatico cada 15 min con conexion.
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

        Log.d(TAG, "WorkManager sync automatico programado")
    }

    /**
     * Vibracion corta al detectar QR valido.
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
                    binding.textViewStatus.text = "Apunta al codigo QR de la tarjeta del matafuegos"
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
     * Dialogo cuando se deniegan permisos de camara.
     */
    private fun mostrarDialogoPermisosDenegados() {
        AlertDialog.Builder(this)
            .setTitle("Permiso de camara necesario")
            .setMessage("Esta app necesita acceso a la camara para escanear codigos QR de matafuegos. Por favor habilita el permiso en Configuracion.")
            .setPositiveButton("Ir a Configuracion") { _, _ ->
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
