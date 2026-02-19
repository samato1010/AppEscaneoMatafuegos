# AppEscaneoMatafuegos

App Android liviana para escanear QR de tarjetas de matafuegos (extintores) del sitio oficial de **AGC Buenos Aires** y enviar la URL al backend.

## Funcionalidad

1. **Escaneo en tiempo real** de QR con la cámara del dispositivo
2. **Validacion automatica**: solo procesa URLs que empiecen con `https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp`
3. **Envio al backend** via POST automatico al detectar QR valido
4. **Feedback visual**: muestra el resultado en pantalla con colores (verde = valido, rojo = no valido)

## Tecnologias

| Componente | Libreria | Version |
|---|---|---|
| Camara | CameraX (camera2 + lifecycle + view) | 1.3.4 |
| Escaneo QR | ML Kit Barcode Scanning | 17.3.0 |
| HTTP Client | Retrofit + Gson | 2.11.0 |
| UI | Material Design 3 + ConstraintLayout | - |
| Lenguaje | Kotlin | 2.0.21 |
| Target SDK | Android 15 (API 35) | - |
| Min SDK | Android 7.0 (API 24) | - |

## Estructura

```
app/src/main/
├── java/com/hst/appescaneomatafuegos/
│   ├── MainActivity.kt       # Pantalla principal: camara + escaneo + envio
│   └── ApiService.kt         # Interface Retrofit + modelos de datos
├── res/
│   ├── layout/
│   │   └── activity_main.xml # Layout: PreviewView + TextView resultado
│   ├── drawable/
│   │   └── scan_frame.xml    # Marco guia para escaneo
│   └── values/
│       ├── strings.xml
│       ├── colors.xml
│       └── themes.xml
└── AndroidManifest.xml        # Permisos CAMERA + INTERNET
```

## Como correr

### Requisitos
- Android Studio Ladybug (2024.2.1) o superior
- JDK 17
- Dispositivo Android con camara (API 24+) o emulador

### Pasos
1. Clonar el repositorio:
   ```bash
   git clone https://github.com/samato1010/AppEscaneoMatafuegos.git
   ```
2. Abrir el proyecto en Android Studio
3. Esperar que Gradle sincronice las dependencias
4. Conectar dispositivo Android (o iniciar emulador)
5. Run > Run 'app'

### Configurar backend
En `ApiService.kt`, cambiar la `BASE_URL`:
```kotlin
private const val BASE_URL = "https://tu-dominio.com/"
```

El endpoint esperado es `POST /api/escaneos` con body:
```json
{
  "url": "https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp?..."
}
```

## Autor

**Sebastian Amato** - [HST SRL](https://github.com/samato1010)
