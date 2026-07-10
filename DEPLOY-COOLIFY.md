# Despliegue de ProTaxi (backend) en Coolify

Guía paso a paso para desplegar el backend Laravel + MySQL + Redis en Coolify.

## 0. Arquitectura

El `Dockerfile` del proyecto construye **una sola imagen** que, vía Supervisor, corre 3 procesos:

| Proceso | Qué hace | Puerto |
|---|---|---|
| **Apache** | Sirve Laravel y hace de proxy WebSocket (`/app/` → Reverb) | 80 |
| **Reverb** | Servidor WebSocket (broadcasting en tiempo real) | 8080 (interno) |
| **Queue worker** | `queue:work redis` (10 procesos) — **necesita Redis** | — |

El `entrypoint.sh` ejecuta en cada arranque: `config:cache` → `storage:link` → `migrate --force`.

Necesitas **3 recursos en Coolify**:
1. **MySQL** (base de datos)
2. **Redis** (colas / caché)
3. **Aplicación** (el backend, build por Dockerfile desde GitHub)

---

## 1. Prerrequisitos

- Una instancia de Coolify funcionando con un **Server** conectado.
- Un **dominio** apuntando al servidor (ej. `app.protaxi.com`) — Coolify emite SSL con Let's Encrypt automáticamente.
- Repo en GitHub: `https://github.com/jonathanSCM/ProTaxi.git`.
  - Conéctalo en Coolify con **GitHub App** (Settings → Sources) o, si es privado, con una **Deploy Key**.

> Crea un **Project** en Coolify (ej. `ProTaxi`) y dentro un **Environment** (`production`). Los 3 recursos deben ir en el mismo project/environment para compartir la red interna.

---

## 2. Base de datos MySQL

1. En el project → **+ New Resource** → **Databases** → **MySQL** (usa 8.0).
2. Configura:
   - **Database**: `deliveryavaroa_app`
   - **Username**: `protaxi`
   - **Password**: (genera uno fuerte, guárdalo)
   - **Root password**: (genera uno fuerte)
3. **Deploy**. Cuando arranque, abre la pestaña del recurso y copia el **hostname interno** (algo como `mysql-xxxx`) y el puerto `3306`.
   - El backend se conectará por ese hostname interno (red Docker de Coolify), **no** por localhost.
4. (Opcional) Activa "Public Port" temporalmente solo para importar el dump desde tu PC (paso 8), luego desactívalo.

---

## 3. Redis

1. Project → **+ New Resource** → **Databases** → **Redis**.
2. Genera y guarda la **password** de Redis.
3. **Deploy** y copia el **hostname interno** (ej. `redis-xxxx`) y puerto `6379`.

---

## 4. Aplicación (backend)

1. Project → **+ New Resource** → **Application** → **Public/Private Repository**.
2. Selecciona el repo `jonathanSCM/ProTaxi`, rama **`main`**.
3. **Build Pack**: elige **Dockerfile** (Coolify detecta el `Dockerfile` en la raíz).
4. **Port (Ports Exposes)**: `80`.
5. **Domain**: pon tu dominio `https://app.protaxi.com` (Coolify gestiona el SSL).
6. Aún **no despliegues** — primero configura las variables (paso 5) y los volúmenes (paso 6).

---

## 5. Variables de entorno

En la app → pestaña **Environment Variables**, pega este bloque y ajusta los valores.

> ⚠️ **Importante (build-time):** las variables `VITE_*` se **incrustan en el JavaScript durante el build** (`npm run build` corre dentro del Dockerfile). En Coolify márcalas como **"Build Variable" / disponible en build**. Si las cambias después, hay que **redeploy** para que tengan efecto.

```env
APP_NAME=ProTaxi
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # genera con: php artisan key:generate --show  (déjalo FIJO para siempre)
APP_URL=https://app.protaxi.com

LOG_CHANNEL=stack
LOG_LEVEL=error

# --- Base de datos (hostname interno del recurso MySQL de Coolify) ---
DB_CONNECTION=mysql
DB_HOST=mysql-xxxx
DB_PORT=3306
DB_DATABASE=deliveryavaroa_app
DB_USERNAME=protaxi
DB_PASSWORD=TU_PASSWORD_MYSQL

# --- Redis (hostname interno del recurso Redis) ---
REDIS_HOST=redis-xxxx
REDIS_PASSWORD=TU_PASSWORD_REDIS
REDIS_PORT=6379

# --- Colas / caché / sesión sobre Redis ---
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
FILESYSTEM_DISK=local

# --- Broadcasting con Reverb (WebSockets) ---
BROADCAST_DRIVER=reverb
REVERB_APP_ID=123456                 # cualquier número
REVERB_APP_KEY=una_clave_aleatoria   # genera un string aleatorio
REVERB_APP_SECRET=un_secreto_aleatorio
REVERB_HOST=app.protaxi.com          # tu dominio (sin https://)
REVERB_PORT=443
REVERB_SCHEME=https
# El servidor Reverb escucha internamente en 0.0.0.0:8080 (ya fijado en supervisord)
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# --- Cliente (Pusher-protocol → apunta a Reverb). SE COMPILAN EN BUILD ---
VITE_PUSHER_APP_KEY=${REVERB_APP_KEY}
VITE_PUSHER_HOST=app.protaxi.com
VITE_PUSHER_PORT=443
VITE_PUSHER_SCHEME=https
VITE_PUSHER_APP_CLUSTER=mt1

# --- Firebase (push notifications) ---
# OJO: es el JSON del service account en BASE64 (contenido, no una ruta).
# Genéralo con:  base64 -w0 firebase_credentials.json
FIREBASE_CREDENTIALS=BASE64_DEL_JSON_DEL_SERVICE_ACCOUNT

# --- Correo (ajusta a tu proveedor SMTP real) ---
MAIL_MAILER=smtp
MAIL_HOST=smtp.tu-proveedor.com
MAIL_PORT=587
MAIL_USERNAME=TU_USUARIO
MAIL_PASSWORD=TU_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@protaxi.com
MAIL_FROM_NAME=ProTaxi
```

**Generar el APP_KEY** (en tu PC, una sola vez):
```bash
php artisan key:generate --show
# copia el valor base64:... a APP_KEY en Coolify
```
> El `APP_KEY` debe permanecer **constante**: si lo cambias, se invalidan sesiones y datos cifrados.

---

## 6. Volúmenes persistentes (¡crítico!)

La imagen es inmutable: en cada redeploy se reconstruye. Sin volúmenes, **se pierden las subidas** (fotos de perfil, logos, documentos de conductores). En la app → **Storage / Persistent Storage**, añade:

| Nombre | Mount Path (en el contenedor) |
|---|---|
| uploads | `/var/www/html/public/uploads` |
| storage-app | `/var/www/html/storage/app` |

> El logo/favicon de ProTaxi vive en `public/uploads/setting/…`, así que el volumen `uploads` conserva la marca entre despliegues.

---

## 7. Credenciales de Firebase (¡se necesitan en DOS sitios!)

El código carga las credenciales del service account por **dos vías distintas**:

| Uso | Mecanismo |
|---|---|
| **Push FCM** (`FcmService`) | env **`FIREBASE_CREDENTIALS`** = el JSON en **base64** (paso 5) |
| **Auth + chat Firestore** (`FirebaseHelper`, `ChatController`) | **archivo** en `public/firebase_credentials.json` |

Por eso debes configurar **ambos**:

1. **Env `FIREBASE_CREDENTIALS`** (paso 5): el JSON del service account en base64
   (`base64 -w0 firebase_credentials.json`).
2. **File Mount** en la app → **Storage**:
   - **Path**: `/var/www/html/public/firebase_credentials.json`
   - **Contenido**: pega el JSON del service account (sin base64, tal cual).

> El `.htaccess` del repo ya bloquea el acceso web directo a
> `public/firebase_credentials.json`, y el `.gitignore` evita que se suba al repo.
> Ambos deben ser del **mismo proyecto Firebase** de esa instancia.

---

## 8. Importar la base de datos

Las migraciones crean las tablas vacías, pero **necesitas los datos de configuración + la cuenta admin + catálogos**. Usa tu BD local ya limpia (solo admin + config).

**a) Exporta tu BD local limpia** (en tu PC):
```bash
& "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe" -u root \
  --single-transaction --routines --triggers deliveryavaroa_app > protaxi-seed.sql
```

**b) Impórtala en el MySQL de Coolify.** Dos opciones:

- **Vía Public Port temporal** (paso 2.4): con el puerto público activo,
  ```bash
  mysql -h TU_IP_COOLIFY -P PUERTO_PUBLICO -u protaxi -p deliveryavaroa_app < protaxi-seed.sql
  ```
  Luego **desactiva** el puerto público.

- **Vía terminal del contenedor** (más seguro): sube `protaxi-seed.sql` y en la
  **Terminal** del recurso MySQL en Coolify:
  ```bash
  mysql -u protaxi -p deliveryavaroa_app < /ruta/protaxi-seed.sql
  ```

> Como el dump incluye la tabla `migrations` (99 filas), el `migrate --force` del arranque será un **no-op** y no habrá conflicto.

---

## 9. Primer deploy y verificación

1. Pulsa **Deploy**. Observa **Logs → Build** (composer install, npm ci, npm run build).
2. Luego **Logs → Runtime**: deberías ver el entrypoint:
   ```
   ==> Caching config...
   ==> Linking storage...
   ==> Running migrations...
   ==> Starting services (Apache + Reverb)...
   ```
3. Abre `https://app.protaxi.com/admin/login` → debe cargar con la marca ProTaxi.
4. Inicia sesión con la cuenta admin (`admin@avaroa.com` y tu contraseña).

---

## 10. WebSockets / Reverb

El `Dockerfile` ya incluye el proxy de Apache (`docker/apache-reverb-proxy.conf`) que reenvía:
- `wss://app.protaxi.com/app/…` → Reverb (8080)
- `https://app.protaxi.com/apps/…` → handshake Pusher

No necesitas exponer un puerto extra ni otro servicio: todo pasa por el 443 del mismo dominio. Solo asegúrate de que `REVERB_HOST`, `REVERB_SCHEME=https`, `REVERB_PORT=443` y los `VITE_PUSHER_*` (build-time) coincidan con tu dominio.

---

## 11. Notas y errores comunes

- **`VITE_*` no aplican tras cambiarlas** → se compilan en build; haz **Redeploy**.
- **APP_KEY vacío o cambiante** → error de cifrado / sesiones caídas. Fíjalo una vez.
- **Uploads que desaparecen tras redeploy** → falta el volumen del paso 6.
- **Push no funciona** → revisa el File Mount de Firebase (paso 7) y la ruta en `FIREBASE_CREDENTIALS`.
- **Colas no procesan** → revisa `REDIS_HOST/REDIS_PASSWORD`; el worker usa `queue:work redis`.
- **502/timeout al desplegar en host con poca RAM** → el `Dockerfile` ya separa la compilación de extensiones PHP para evitar OOM; si aún falla, sube la RAM del server o usa swap.
- **Migraciones con error** → el entrypoint continúa igualmente (`|| echo WARNING`); revisa logs si algo del esquema no cuadra con el dump.

---

### Resumen de recursos

```
Project: ProTaxi / production
├── MySQL     (deliveryavaroa_app)   ← importar protaxi-seed.sql
├── Redis     (colas/caché/sesión)
└── App       (Dockerfile, puerto 80, dominio + SSL)
    ├── Env vars (incl. VITE_* en build)
    ├── Volúmenes: public/uploads, storage/app
    └── File mount: firebase-credentials.json
```
