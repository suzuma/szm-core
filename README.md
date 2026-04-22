# SZM-Core

Un framework PHP minimalista y de alto rendimiento, diseñado para aplicaciones web escalables con seguridad de nivel empresarial.

## Características principales

- **WAF (Web Application Firewall)** — Detección de SQL injection, XSS, XXE, SSRF, path traversal, command injection, open redirect, bots IA, análisis de comportamiento y reputación de IP.
- **Autenticación y RBAC** — Sesiones seguras, roles y permisos, bloqueo por intentos fallidos, reset de contraseña.
- **CSRF Protection** — Token por petición con integración automática en Twig.
- **Audit Log** — Registro de eventos de autenticación y operaciones CRUD con diff JSON.
- **ORM Eloquent** — Query builder, relaciones y transacciones vía `illuminate/database`.
- **Twig 3** — Motor de plantillas con caché en producción y helpers globales (`csrf_field`, `asset`, `url`, `flash`).
- **Routing Phroute** — Grupos de rutas, filtros before/after, type constraints en parámetros.
- **Monolog** — Canales de acceso, errores y debug con rotación diaria.

## Tech Stack

| Capa | Tecnología |
|---|---|
| Lenguaje | PHP 8.3+ (strict types) |
| ORM | illuminate/database ^13.3 |
| Eventos | illuminate/events ^13.3 |
| Router | phroute/phroute ^2.2 |
| Templates | twig/twig ^3.0 |
| Logging | monolog/monolog ^3.10 |
| Env | vlucas/phpdotenv ^5.6 |
| Base de datos | MySQL 5.7+ / 8.0+ |
| Servidor | Apache mod_rewrite o PHP built-in server |

## Instalación

### Requisitos previos

- PHP 8.3+
- MySQL 5.7+ o 8.0+
- Composer
- Apache con `mod_rewrite` (producción) o PHP built-in server (desarrollo)

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
```

### Opciones del migrador

```bash
php database/migrate.php             # Crea tablas y ejecuta seeds
php database/migrate.php --no-seed   # Solo crea tablas
php database/migrate.php --fresh     # DROP + recrear desde cero
```

Durante el seed se solicita interactivamente el email, nombre y contraseña del administrador.

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

# Redis (opcional — sin esto el WAF opera en modo MySQL puro)
REDIS_HOST=
REDIS_PORT=6379

# Telegram (opcional — notificaciones de intrusión en tiempo real)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

## Estructura del proyecto

```
szm-core/
├── app/
│   ├── Controllers/        # Manejadores HTTP
│   ├── Models/             # Modelos Eloquent (User, Role, Permission, AuditLog)
│   ├── Services/           # Lógica de negocio (AuthService)
│   ├── Helpers/            # Funciones auxiliares (Flash, OldInput)
│   ├── Events/             # Eventos de dominio
│   ├── Views/              # Plantillas Twig
│   ├── routes/web.php      # Definición de rutas
│   ├── filters.php         # Middleware before/after
│   └── providers.php       # Registro de servicios y listeners
│
├── core/
│   ├── Bootstrap/          # Secuencia de arranque (Application, ExceptionHandler)
│   ├── Security/
│   │   ├── Session.php
│   │   ├── CsrfToken.php
│   │   └── Waf/            # WAF (detección, comportamiento, identidad, HTTP)
│   ├── Auth/               # Fachada de autenticación
│   ├── Database/           # Inicialización de Eloquent
│   ├── Http/               # Request, StaticFileHandler
│   ├── Events/             # Dispatcher de eventos
│   └── ServicesContainer.php
│
├── database/
│   ├── migrate.php         # Runner de migraciones
│   ├── migrations/         # 7 archivos SQL (RBAC + WAF)
│   └── seeds/              # Datos iniciales (admin user)
│
├── tests/
│   └── Waf/                # Suite completa de tests del WAF (222 tests)
│       ├── Detection/      # SQL injection, XSS, XXE, SSRF, path traversal, etc.
│       ├── Normalize/      # Normalización anti-bypass
│       ├── Identity/       # Resolución de IP
│       └── Config/         # Invariantes de configuración
│
├── public/                 # Assets web (CSS, JS, imágenes)
├── storage/                # Logs, caché Twig, uploads
├── index.php               # Front controller
├── config.php              # Configuración de la aplicación
└── .env.example            # Plantilla de variables de entorno
```

## Rutas disponibles

| Método | URI | Descripción |
|---|---|---|
| GET/POST | `/login` | Autenticación |
| GET/POST | `/forgot-password` | Recuperación de contraseña |
| GET/POST | `/reset-password/{token}` | Reset de contraseña |
| POST | `/logout` | Cerrar sesión |
| GET | `/` | Dashboard (requiere auth) |
| GET/POST | `/admin/users` | CRUD de usuarios (admin) |
| PUT | `/admin/users/{id}` | Actualizar usuario (admin) |
| PATCH | `/admin/users/{id}/toggle` | Activar/desactivar usuario (admin) |
| DELETE | `/admin/users/{id}` | Eliminar usuario (admin) |
| GET | `/admin/waf` | Dashboard WAF (admin) |
| GET | `/admin/waf/blocked-ips` | IPs bloqueadas (admin) |
| GET | `/admin/waf/attack-logs` | Logs de ataques (admin) |
| POST | `/admin/waf/unban/{id}` | Desbloquear IP (admin) |
| GET | `/admin/audit-log` | Registro de auditoría (admin) |

## Tests

```bash
# Ejecutar todos los tests
composer test

# Suite específica
vendor/bin/phpunit --testsuite "WAF — Detección de ataques"

# Con reporte de cobertura HTML
vendor/bin/phpunit --coverage-html coverage/
```

Suites disponibles: `WAF — Detección de ataques`, `WAF — Normalización anti-bypass`, `WAF — Identidad / IP`, `WAF — Configuración`.

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
              7. Ejecutar WAF
              8. Iniciar sesión + CSRF token
              9. Escribir log de acceso
              └─▶ Router (Phroute) → Controller → Response
```

## Licencia

MIT — ver archivo [LICENSE](LICENSE) para más detalles.

## Autor

**Noe Cazarez Camargo** — [suzuma@gmail.com](mailto:suzuma@gmail.com)