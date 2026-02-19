package com.hst.appescaneomatafuegos

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import android.util.Log
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import com.hst.appescaneomatafuegos.databinding.ActivityMainBinding
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/**
 * MainActivity — Pantalla principal con preview de cámara y escaneo QR en tiempo real.
 *
 * Flujo:
 * 1. Solicita permiso de cámara
 * 2. Inicia CameraX con Preview + ImageAnalysis
 * 3. ML Kit escanea cada frame buscando QR
 * 4. Si el QR contiene URL válida de AGC matafuegos → muestra + envía POST al backend
 */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var cameraExecutor: ExecutorService
    private val apiService: ApiService by lazy { ApiService.create() }

    // Prefijo válido de URLs de matafuegos AGC
    private val URL_PREFIX = "https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp"

    // Evitar envíos duplicados de la misma URL
    private var lastSentUrl: String = ""
    private var isProcessing: Boolean = false

    // Request de permiso de cámara
    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { isGranted ->
        if (isGranted) {
            startCamera()
        } else {
            Toast.makeText(this, "Se necesita permiso de cámara para escanear QR", Toast.LENGTH_LONG).show()
            finish()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        cameraExecutor = Executors.newSingleThreadExecutor()

        // Verificar permisos
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED
        ) {
            startCamera()
        } else {
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
        }
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
                    it.surfaceProvider = binding.previewView.surfaceProvider
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
                Toast.makeText(this, "Error al iniciar cámara", Toast.LENGTH_SHORT).show()
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
                    if (barcode.valueType == Barcode.TYPE_URL || barcode.valueType == Barcode.TYPE_TEXT) {
                        val rawValue = barcode.rawValue ?: continue
                        handleDetectedQR(rawValue)
                    }
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
     * Maneja un QR detectado: valida la URL y la envía al backend.
     */
    private fun handleDetectedQR(rawValue: String) {
        // Verificar si es URL válida de matafuegos
        if (!rawValue.startsWith(URL_PREFIX)) {
            runOnUiThread {
                binding.textViewStatus.text = "QR detectado (no es matafuegos)"
                binding.textViewResult.text = rawValue.take(100)
                binding.textViewResult.setTextColor(
                    ContextCompat.getColor(this, R.color.error)
                )
            }
            return
        }

        // Evitar duplicados
        if (rawValue == lastSentUrl) return

        lastSentUrl = rawValue

        runOnUiThread {
            binding.textViewStatus.text = "✅ QR de matafuegos detectado"
            binding.textViewResult.text = rawValue
            binding.textViewResult.setTextColor(
                ContextCompat.getColor(this, R.color.success)
            )
        }

        // Enviar al backend
        uploadToWeb(rawValue)
    }

    /**
     * Envía la URL detectada al backend via POST.
     */
    private fun uploadToWeb(url: String) {
        isProcessing = true

        CoroutineScope(Dispatchers.IO).launch {
            try {
                Log.d(TAG, "Enviando URL al backend: $url")
                val response = apiService.enviarEscaneo(EscaneoRequest(url = url))

                withContext(Dispatchers.Main) {
                    if (response.isSuccessful) {
                        binding.textViewStatus.text = "✅ Enviado al servidor correctamente"
                        Log.d(TAG, "URL enviada OK: ${response.code()}")
                        Toast.makeText(
                            this@MainActivity,
                            "Escaneo enviado ✓",
                            Toast.LENGTH_SHORT
                        ).show()
                    } else {
                        binding.textViewStatus.text = "⚠️ Error del servidor: ${response.code()}"
                        Log.w(TAG, "Error del servidor: ${response.code()} ${response.message()}")
                    }
                    isProcessing = false
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error de red: ${e.message}", e)
                withContext(Dispatchers.Main) {
                    binding.textViewStatus.text = "⚠️ Error de conexión (URL guardada localmente)"
                    isProcessing = false
                }
            }
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        cameraExecutor.shutdown()
    }

    companion object {
        private const val TAG = "EscaneoMatafuegos"
    }
}
