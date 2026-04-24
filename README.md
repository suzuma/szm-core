# SZM-Core

Un framework PHP minimalista y de alto rendimiento, diseñado para aplicaciones web escalables con seguridad de nivel empresarial.

## Características principales

- **WAF (Web Application Firewall)** — Detección de SQL injection, XSS, XXE, SSRF, path traversal, command injection, open redirect, bots IA, análisis de comportamiento, reputación de IP y mapa geográfico de amenazas.
- **Autenticación y RBAC** — Sesiones seguras, roles y permisos, bloqueo por intentos fallidos, reset de contraseña, rate limiter de login, historial de contraseñas (previene reutilización de las últimas 5).
- **CSRF Protection** — Token por petición con integración automática en Twig.
- **Audit Log** — Registro de eventos de autenticación y operaciones CRUD con diff JSON.
- **ORM Eloquent** — Query builder, relaciones y transacciones vía `illuminate/database`.
- **Twig 3** — Motor de plantillas con caché en producción, helpers globales y componentes reutilizables (modal, pagination, alert, stat_card).
- **Routing Phroute** — Grupos de rutas, filtros before/after, type constraints en parámetros.
- **Monolog** — Canales de acceso, errores, debug y mail con rotación diaria.
- **Mail** — `MailerInterface` con `SmtpMailer` (PHPMailer) y `NullMailer` (log-only) intercambiables vía DI.
- **Cache** — `CacheInterface` con drivers `ApcuCache`, `FileCache` y `NullCache`; fachada estática `Cache`.
- **Storage** — `StorageInterface` con `LocalStorage`; fachada estática `Storage` para uploads seguros.
- **Rate Limiter genérico** — `RateLimiter` basado en `CacheInterface`; clave arbitraria, max hits y ventana TTL configurables.
- **Notificaciones Telegram** — `TelegramNotifier` para alertas de intrusión y eventos críticos en tiempo real.
- **Health Check** — `GET /health` responde JSON con estado de DB, caché y storage; HTTP 200/503 para load balancers y monitoreo.
- **CLI** — `bin/szm` con generadores de código (`make:controller`, `make:model`, `make:migration`, `make:request`, `make:seeder`, `make:event`) y runner de migraciones.
- **Query Debug Panel** — Panel flotante en el layout con todas las queries SQL ejecutadas (timing, bindings); solo visible en `APP_ENV=dev`.
- **Dark mode** — Toggle claro/oscuro persistido en `localStorage`; respeta `prefers-color-scheme` del SO.

## Tech Stack

| Capa | Tecnología |
|---|---|
| Lenguaje | PHP 8.3+ (strict types) |
| ORM | illuminate/database ^13.3 |
| Eventos | illuminate/events ^13.3 |
| Router | phroute/phroute ^2.2 |
| Templates | twig/twig ^3.0 |
| Logging | monolog/monolog ^3.10 |
| Mail | phpmailer/phpmailer ^7.0 |
| Env | vlucas/phpdotenv ^5.6 |
| Base de datos | MySQL 5.7+ / 8.0+ |
| Caché | APCu (preferido) / sistema de archivos |
| Servidor | Apache mod_rewrite o PHP built-in server |

## Instalación

### Requisitos previos

- PHP 8.3+
- MySQL 5.7+ o 8.0+
- Composer
- Apache con `mod_rewrite` (producción) o PHP built-in server (desarrollo)
- APCu (opcional — mejora el rendimiento del WAF y del rate limiter)

### Pasos

```bash
# 1. Clonar el repositorio
git clone https://github.com/tu-usuario/szm-core.git
cd szm-core

# 2. Instalar dependencias
composer install

# 3. Configurar el entorno
cp .env.example .env
# Editar .env con tus credenciales de base de datos y configuración de la app

# 4. Crear tablas y datos iniciales
php database/migrate.php

# 5. Permisos de escritura
chmod -R 755 storage/
chmod -R 755 public/
chmod +x bin/szm
```

### Opciones del migrador

```bash
php database/migrate.php             # Crea tablas y ejecuta seeds
php database/migrate.php --no-seed   # Solo crea tablas
php database/migrate.php --fresh     # DROP + recrear desde cero

# O usando el CLI
bin/szm migrate
bin/szm migrate --fresh
```

Durante el seed se solicita interactivamente el email, nombre y contraseña del administrador.

## CLI — `bin/szm`

```bash
bin/szm list                              # Listar todos los comandos disponibles

# Generadores de código
bin/szm make:controller NombreController
bin/szm make:controller Admin/DashboardController   # con subdirectorio
bin/szm make:model Producto
bin/szm make:migration crear_tabla_productos
bin/szm make:request StoreProductoRequest
bin/szm make:seeder ProductoSeeder
bin/szm make:seeder ProductoSeeder --php  # seeder PHP interactivo (default: SQL)
bin/szm make:event InventarioActualizado

# Migraciones
bin/szm migrate
bin/szm migrate --fresh
bin/szm migrate --no-seed
```

## Configuración del servidor web

### Apache (recomendado para producción)

Apuntar `DocumentRoot` a la raíz del proyecto. El `.htaccess` ya incluido gestiona el routing y las cabeceras de seguridad.

```apache
DocumentRoot /ruta/a/szm-core
```

### PHP built-in server (desarrollo)

```bash
composer serve
# Equivale a: php -S localhost:8000 index.php
```

Acceder en `http://localhost:8000`.

## Variables de entorno

```dotenv
# Aplicación
APP_ENV=dev              # dev | prod | stop
APP_URL=                 # https://miapp.com (vacío = autodetect)
APP_TIMEZONE=America/Mexico_City
EMPRESA_NOMBRE=MiSistema

# Base de datos
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=szm_core
DB_USERNAME=root
DB_PASSWORD=secret

# Sesión
SESSION_NAME=SZM_SESSION
SESSION_LIFETIME=120     # minutos
SESSION_SECURE=false     # true en producción con HTTPS
SESSION_SAMESITE=Lax

# WAF — IPs de confianza (vacío = inspecciona todas)
WAF_TRUSTED_IPS=         # Ej: 203.0.113.10,203.0.113.11
WAF_BYPASS_SECRET=       # Token HMAC para bypass vía cookie (admin)

# Correo electrónico
# Vacío = NullMailer (loguea en storage/logs/mail-*.log, no envía)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=SZM
MAIL_ENCRYPTION=tls      # tls | ssl | (vacío = sin cifrado)
MAIL_DEBUG=false         # true = debug SMTP en consola (solo dev)

# Telegram (opcional — notificaciones de intrusión en tiempo real)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

## Estructura del proyecto

```
szm-core/
├── app/
│   ├── Controllers/        # Manejadores HTTP
│   │   └── Admin/          # WafController, UserController, ConfigController, AuditController
│   ├── Http/
│   │   └── Requests/       # FormRequests declarativos (Login, Forgot, Reset, StoreUser, UpdateUser)
│   ├── Models/             # Eloquent: User, Role, Permission, AuditLog, PasswordHistory
│   ├── Services/           # AuthService
│   ├── Events/             # UserLoggedIn, PasswordResetRequested
│   ├── Helpers/            # Flash, OldInput
│   ├── Views/
│   │   ├── layouts/        # app.twig (+ query debug panel), auth.twig
│   │   ├── components/     # modal.twig, pagination.twig, stat_card.twig, empty_state.twig
│   │   ├── admin/          # Vistas de panel (users, waf, audit-log, config)
│   │   └── auth/           # login, forgot-password, reset-password
│   ├── routes/web.php      # Definición de rutas
│   ├── filters.php         # Middleware before/after (auth, admin, guest, csrf)
│   └── providers.php       # Registro de servicios y listeners
│
├── core/
│   ├── Bootstrap/          # Application, ExceptionHandler
│   ├── Console/            # ConsoleApplication, CommandInterface, Commands/
│   ├── Notifications/      # TelegramNotifier
│   ├── Security/
│   │   ├── Session.php
│   │   ├── CsrfToken.php
│   │   ├── LoginRateLimiter.php
│   │   ├── RateLimiter.php  # Rate limiter genérico basado en CacheInterface
│   │   └── Waf/             # WAF (detección, comportamiento, identidad, HTTP, geo)
│   ├── Auth/               # Fachada de autenticación
│   ├── Cache/              # CacheInterface, ApcuCache, FileCache, NullCache, Cache (fachada)
│   ├── Config/             # EnvWriter (edición atómica de .env)
│   ├── Database/           # Inicialización de Eloquent
│   ├── Events/             # EventDispatcher
│   ├── Http/               # Request, Response, StaticFileHandler
│   ├── Mail/               # MailerInterface, SmtpMailer, NullMailer, Message
│   ├── Storage/            # StorageInterface, LocalStorage, Storage (fachada)
│   └── ServicesContainer.php
│
├── bin/
│   └── szm                 # CLI ejecutable
│
├── database/
│   ├── migrate.php         # Runner de migraciones
│   ├── migrations/         # 10 archivos SQL (RBAC + WAF + geo + password history)
│   └── seeds/              # Datos iniciales (admin user)
│
├── tests/
│   ├── Support/
│   │   └── FakeCache.php   # CacheInterface in-memory para tests
│   ├── Http/
│   │   ├── ResponseTest.php
│   │   └── Requests/
│   │       └── LoginRequestTest.php
│   ├── Security/
│   │   └── RateLimiterTest.php
│   └── Waf/                # Suite WAF (247 tests)
│       ├── Detection/      # SQL injection, XSS, XXE, SSRF, path traversal, etc.
│       ├── Normalize/      # Normalización anti-bypass
│       ├── Identity/       # Resolución de IP
│       └── Config/         # Invariantes de configuración
│
├── public/                 # Assets web (CSS, JS, imágenes)
│   ├── css/
│   │   ├── app.css         # Layout principal + dark mode + componentes
│   │   └── auth.css        # Layout de autenticación + dark mode
│   └── assets/             # Logo, favicon
├── storage/                # Logs, caché Twig, uploads
├── index.php               # Front controller
├── config.php              # Configuración de la aplicación
├── CHANGELOG.md            # Historial de cambios (Keep a Changelog)
└── .env.example            # Plantilla de variables de entorno
```

## Rutas disponibles

| Método | URI | Auth | Descripción |
|---|---|---|---|
| GET | `/health` | No | Estado de DB, caché y storage (JSON) |
| GET/POST | `/login` | Guest | Autenticación |
| GET/POST | `/forgot-password` | Guest | Solicitar reset de contraseña |
| GET/POST | `/reset-password/{token}` | Guest | Aplicar nueva contraseña |
| POST | `/logout` | Auth | Cerrar sesión |
| GET | `/session-keepalive` | Auth | Extender sesión (AJAX) |
| GET | `/` | Auth | Dashboard |
| GET | `/admin/users` | Admin | Listado de usuarios |
| POST | `/admin/users` | Admin | Crear usuario |
| PUT | `/admin/users/{id}` | Admin | Actualizar usuario |
| PATCH | `/admin/users/{id}/toggle` | Admin | Activar/desactivar usuario |
| DELETE | `/admin/users/{id}` | Admin | Eliminar usuario |
| GET | `/admin/waf` | Admin | Dashboard WAF |
| GET | `/admin/waf/blocked-ips` | Admin | IPs bloqueadas |
| GET | `/admin/waf/attack-logs` | Admin | Logs de ataques |
| POST | `/admin/waf/unban/{id}` | Admin | Desbloquear IP |
| GET | `/admin/waf/geo-map` | Admin | Mapa geográfico de amenazas |
| POST | `/admin/waf/sync-geo/{id}` | Admin | Sincronizar geolocalización por IP |
| GET | `/admin/waf/export/attack-logs` | Admin | Exportar logs a CSV |
| GET | `/admin/waf/export/blocked-ips` | Admin | Exportar IPs bloqueadas a CSV |
| GET | `/admin/audit-log` | Admin | Registro de auditoría |
| GET/POST | `/admin/config` | Admin | Configuración del sistema vía UI |

## Tests

```bash
# Ejecutar todos los tests
composer test

# Suite específica
vendor/bin/phpunit --testsuite "WAF — Detección de ataques"
vendor/bin/phpunit --testsuite "HTTP — Response y Requests"
vendor/bin/phpunit --testsuite "Security — RateLimiter"

# Con reporte de cobertura HTML
vendor/bin/phpunit --coverage-html coverage/
```

Suites disponibles:

| Suite | Tests |
|---|---|
| WAF — Detección de ataques | SQL injection, XSS, XXE, SSRF, path traversal, command injection, open redirect |
| WAF — Normalización anti-bypass | Decodificación URL, hex, unicode, case folding |
| WAF — Identidad / IP | Resolución de IP real detrás de proxies |
| WAF — Configuración | Invariantes de configuración del WAF |
| Config — EnvWriter | Lectura y escritura atómica de variables de entorno |
| HTTP — Response y Requests | `Response` value object, `LoginRequest` validación |
| Security — RateLimiter | `RateLimiter` genérico con caché fake |

**Total: 291 tests, 368 assertions**

## Herramientas de desarrollo

```bash
composer analyze   # Análisis estático con PHPStan (nivel configurado en phpstan.neon)
composer format    # Formato de código con PHP-CS-Fixer
composer serve     # Servidor de desarrollo en localhost:8000
```

## Flujo de arranque

Cada petición HTTP sigue este orden estricto:

```
REQUEST
  └─▶ index.php
        └─▶ Application::boot()
              1. Bloquear URIs sensibles (.env, .git, .sql, etc.)
              2. Cargar variables de entorno (.env)
              3. Cargar configuración (config.php)
              4. Configurar PHP runtime (timezone, error reporting)
              5. Inicializar base de datos (Eloquent)
              6. Verificar estado del sistema (modo mantenimiento)
              7. Ejecutar WAF (+ inyección de CSP nonce)
              8. Iniciar sesión + CSRF token
              9. Registrar servicios y listeners (providers.php)
             10. Escribir log de acceso
              └─▶ Router (Phroute) → Controller → Response
```

## Licencia

MIT — ver archivo [LICENSE](LICENSE) para más detalles.

## Autor

**Noe Cazarez Camargo** — [suzuma@gmail.com](mailto:suzuma@gmail.com)