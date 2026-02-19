# AppEscaneoMatafuegos

App Android liviana para escanear QR de tarjetas de matafuegos (extintores) del sitio oficial de **AGC Buenos Aires** y enviar la URL al backend de HST SRL.

## Funcionalidad

1. **Escaneo en tiempo real** de QR con la camara del dispositivo
2. **Validacion automatica**: solo procesa URLs que empiecen con `https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp`
3. **Envio al backend** via POST automatico al detectar QR valido
4. **Soporte offline con Room**: si no hay conexion, guarda el escaneo localmente para enviar despues
5. **Boton de sincronizacion manual** (FAB) con badge de pendientes
6. **Sync automatico** cada 15 minutos via WorkManager cuando hay conexion
7. **Feedback visual con Snackbar**: verde (enviado), naranja (offline), rojo (error), azul (duplicado)
8. **Vibracion** al detectar QR valido
9. **Hint inteligente**: si no detecta QR por 5 segundos, muestra ayuda

## Tecnologias

| Componente | Libreria | Version |
|---|---|---|
| Camara | CameraX (camera2 + lifecycle + view) | 1.3.4 |
| Escaneo QR | ML Kit Barcode Scanning | 17.3.0 |
| HTTP Client | Retrofit + Gson + OkHttp | 2.11.0 |
| Base de datos offline | Room (runtime + ktx + compiler) | 2.6.1 |
| Sync periodico | WorkManager | 2.10.0 |
| UI | Material Design 3 + ConstraintLayout | - |
| Lenguaje | Kotlin | 2.1.10 |
| Target SDK | Android 16 (API 36) | - |
| Min SDK | Android 7.0 (API 24) | - |

## Estructura

```
app/src/main/
├── java/com/hst/appescaneomatafuegos/
│   ├── MainActivity.kt          # Pantalla principal: camara + escaneo + envio + sync
│   ├── ApiService.kt            # Interface Retrofit + modelos de datos
│   ├── SyncWorker.kt            # WorkManager para sync automatico
│   └── data/
│       ├── EscaneoEntity.kt     # Entidad Room (url, fecha, estado, intentos)
│       ├── EscaneoDao.kt        # DAO: insert, update, query pendientes
│       ├── AppDatabase.kt       # Database singleton
│       └── EscaneoRepository.kt # Repository: Room + Retrofit combinados
├── res/
│   ├── layout/
│   │   └── activity_main.xml    # Layout: PreviewView + FAB sync + ProgressBar
│   ├── drawable/
│   │   └── scan_frame.xml       # Marco guia para escaneo
│   └── values/
│       ├── strings.xml
│       ├── colors.xml
│       └── themes.xml
└── AndroidManifest.xml           # Permisos CAMERA + INTERNET + VIBRATE
```

## Flujo de uso

```
Escanea QR valido
    |-- Con conexion -> Enviar al backend -> Verde "Enviado"
    |-- Sin conexion -> Guardar en Room -> Naranja "Pendiente"

Boton Sync (FAB)
    |-- Envia todos los pendientes -> Actualiza estado en Room

WorkManager (cada 15 min)
    |-- Si hay conexion -> Envia pendientes automaticamente
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

### Backend
La app envia POST a `https://hst.ar/belga/recibir_escaneo.php` con body:
```json
{
  "url": "https://dghpsh.agcontrol.gob.ar/matafuegos/datosEstampilla.jsp?p_tarjeta=..."
}
```

Para cambiar el backend, editar `BASE_URL` en `ApiService.kt`.

### Probar offline
1. Activar modo avion en el celular
2. Escanear un QR de matafuegos -> deberia mostrar "Guardado offline"
3. Desactivar modo avion
4. Presionar el boton de sync (FAB) -> deberia sincronizar los pendientes

## Autor

**Sebastian Amato** - [HST SRL](https://github.com/samato1010)
