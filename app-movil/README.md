# Aplicación Móvil — Sistema Inteligente de Control de Peaje (Flutter / Android)

Aplicación móvil en **Flutter** conectada a la API REST del subdominio HTTPS (`sistema-peaje/`).

## Funcionalidades (Sección 4.1 y 4.4)
- **Inicio de sesión exclusivo con Google (OAuth 2.0 / Google Sign-In):** valida el `idToken` en `POST /api/v1/auth/google`, almacena el token Bearer de sesión y deniega cuentas fuera de la lista blanca.
- **Modo Caseta:** vista de cámara en vivo con envío continuo configurable (**2 a 5 fps**) o captura manual a `POST /api/v1/pasos/frame`. Muestra placa leída, categoría, ejes, pasajeros, tarifa (Bs), estado (`REGISTRADO` / `OBSERVADO` / `ERROR_INFERENCIA`), alertas generadas y resumen diario de la caseta.
- **Modo Consulta:** interfaz conversacional con el agente (`POST /api/v1/agente/chat`) por texto o por fotografía (cámara/galería), mostrando los 4 estados de placa (`REGISTRADA_CON_PASO`, `REGISTRADA_SIN_PASO`, `IDENTIFICADA_NO_REGISTRADA`, `NO_EXISTE`).

## Compilación y ejecución
```bash
cd app-movil
flutter pub get
flutter analyze
flutter build apk --release \
  --dart-define=API_BASE_URL=https://peaje.tu-dominio.com \
  --dart-define=GOOGLE_CLIENT_ID=TU_WEB_CLIENT_ID.apps.googleusercontent.com
```
También puedes cambiar la URL del servidor y el `Google Web Client ID` directamente desde el ícono de **Ajustes (⚙)** en la pantalla de Login sin recompilar.

## Configuración de Google Cloud Console para Android
1. Crea un ID de cliente OAuth de tipo **Android** con el nombre de paquete `bo.edu.unifranz.peaje_movil` y la huella **SHA-1** de tu keystore (`keytool -list -v -keystore ...`).
2. Usa el **Web Client ID** (el mismo que pusiste en `GOOGLE_CLIENT_ID` del servidor PHP) como `GOOGLE_CLIENT_ID` en la app para que Google devuelva un `idToken` válido para el backend.
