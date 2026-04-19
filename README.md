# SZM-Core

**Framework PHP minimalista y de alto rendimiento** diseñado para ser el núcleo reutilizable de múltiples aplicaciones web. Cada proyecto arranca desde aquí y extiende solo lo que necesita — sin arrastrar código que no usa.

---

## Características principales

| Pilar | Qué incluye |
|---|---|
| **Autenticación** | Login, logout, recuperación de contraseña, bloqueo por intentos fallidos |
| **RBAC** | Roles → Permisos (BelongsToMany), Auth facade con `can()` |
| **Seguridad** | WAF 7 capas, CSRF timing-safe, sesión segura, rate limiting |
| **Arquitectura** | Service Container, Event Dispatcher, FormRequest, Facade pattern |
| **UI/UX** | Design system con tokens CSS, componentes Twig reutilizables, toast, breadcrumbs |
| **Audit Log** | Registro automático de acciones críticas con trait `Auditable` |
| **Migraciones** | SQL puro + runner CLI para instalación sin dependencias externas |

---

## Requisitos

- PHP **8.3+** con extensiones: `pdo_mysql`, `mbstring`, `openssl`, `json`
- MySQL / MariaDB **8.0+**
- Composer **2.x**
- Servidor web con soporte a URL rewriting (Apache / Nginx / PHP built-in)

---

## Instalación

```bash
# 1. Clonar
git clone https://github.com/suzuma/szm-core.git
cd szm-core

# 2. Dependencias
composer install

# 3. Entorno
cp .env.example .env
# Editar .env con tus credenciales de base de datos

# 4. Base de datos
php database/migrate.php --seed

# 5. Usuario administrador (interactivo)
php database/seeds/003_admin_user.php

# 6. Servidor de desarrollo
php -S localhost:8000
```

---

## Estructura del proyecto

```
szm-core/
├── app/                        # Capa de aplicación
│   ├── Controllers/            # BaseController + Auth + Error + Home
│   │   └── Admin/              # AuditController
│   ├── Events/                 # UserLoggedIn, PasswordResetRequested
│   ├── Helpers/                # Flash, OldInput
│   ├── Http/                   # Request (FormRequest base)
│   │   └── Requests/           # LoginRequest, ForgotRequest, ResetRequest
│   ├── Models/                 # User, Role, Permission, AuditLog
│   │   └── Concerns/           # Trait Auditable
│   ├── Services/               # AuthService
│   ├── Views/                  # Plantillas Twig
│   │   ├── admin/              # Panel de auditoría
│   │   ├── auth/               # Login, forgot, reset
│   │   ├── components/         # alert, badge, button, input, stat_card, empty_state
│   │   ├── errors/             # 403, 404, 419, 500, 503
│   │   ├── home/               # Dashboard de inicio
│   │   └── layouts/            # app.twig, auth.twig
│   ├── filters.php             # Filtros Phroute: auth, guest, csrf, admin
│   ├── providers.php           # Registro de servicios y listeners
│   └── routes/web.php          # Definición de rutas
│
├── core/                       # Núcleo del framework
│   ├── Auth/Auth.php           # Facade de autenticación
│   ├── Bootstrap/              # Application, ExceptionHandler
│   ├── Contracts/              # MailerInterface
│   ├── Database/DbContext.php  # Inicialización de Eloquent (Capsule)
│   ├── Events/EventDispatcher.php
│   ├── Http/                   # Request singleton, StaticFileHandler
│   ├── Security/               # CsrfToken, Session, WAF (7 capas)
│   ├── Log.php                 # Facade Monolog multicanal
│   ├── ServicesContainer.php   # IoC Container con lazy binding
│   └── TwigFactory.php         # Configuración de Twig + globals
│
├── database/
│   ├── migrations/             # 001…006 archivos SQL
│   ├── seeds/                  # Roles, permisos, usuario admin
│   └── migrate.php             # Runner CLI
│
├── public/
│   ├── assets/                 # logo.png, logo.svg
│   └── css/                    # app.css, auth.css
│
├── storage/
│   ├── cache/twig/             # Caché de plantillas (prod)
│   └── logs/                   # Monolog (access, error, daily)
│
├── config.php                  # Configuración central (lee .env)
├── .env.example                # Plantilla de variables de entorno
└── index.php                   # Entry point
```

---

## Configuración (.env)

```dotenv
APP_ENV=dev                     # dev | prod | stop
APP_URL=http://localhost:8000
APP_TIMEZONE=America/Mazatlan

EMPRESA_NOMBRE="Mi Sistema"

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=szm_core
DB_USERNAME=root
DB_PASSWORD=

SESSION_NAME=SZM_SESSION
SESSION_LIFETIME=120            # minutos
SESSION_SECURE=false
SESSION_SAMESITE=Lax

# WAF (opcional)
WAF_BYPASS_SECRET=              # Secret para token de bypass
REDIS_HOST=                     # Redis para rate limiting en memoria

# Notificaciones WAF (opcional)
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

---

## Migraciones

```bash
# Crear tablas
php database/migrate.php

# Crear tablas + datos base (roles y permisos)
php database/migrate.php --seed

# Reset completo (DROP → CREATE → seed)
php database/migrate.php --fresh

# Crear usuario administrador
php database/seeds/003_admin_user.php
```

### Tablas del sistema

| Tabla | Propósito |
|---|---|
| `szm_roles` | Roles RBAC |
| `szm_permissions` | Permisos atómicos (`modulo.accion`) |
| `szm_users` | Usuarios — campos mínimos de auth |
| `szm_role_permissions` | Pivot roles ↔ permisos |
| `szm_audit_log` | Audit trail de acciones críticas |
| `waf_blocked_ips_szm` | IPs y fingerprints bloqueados |
| `waf_attack_logs_szm` | Log de ataques detectados |
| `waf_requests_szm` | Rate limiting por IP |
| `waf_cloud_ranges_szm` | Rangos cloud (AWS, GCP…) |

---

## Rutas del núcleo

| Método | URI | Acción | Filtro |
|---|---|---|---|
| GET | `/login` | Formulario de login | guest |
| POST | `/login` | Procesar login | guest |
| POST | `/logout` | Cerrar sesión | auth |
| GET | `/forgot-password` | Formulario recuperación | guest |
| POST | `/forgot-password` | Enviar token | guest |
| GET | `/reset-password/{token}` | Formulario nueva contraseña | guest |
| POST | `/reset-password` | Aplicar nueva contraseña | guest |
| GET | `/session-keepalive` | Renovar sesión (AJAX) | auth |
| GET | `/` | Home / Dashboard | auth |
| GET | `/admin/audit-log` | Panel de auditoría | admin |

---

## Cómo extender para un proyecto

### 1. Agregar un modelo

```php
// app/Models/Product.php
namespace App\Models;

use App\Models\Concerns\Auditable;

class Product extends BaseModel
{
    use Auditable; // Registra create/update/delete en szm_audit_log

    protected $table    = 'products';
    protected $fillable = ['name', 'price', 'active'];
}
```

### 2. Agregar un controlador

```php
// app/Controllers/ProductController.php
namespace App\Controllers;

class ProductController extends BaseController
{
    public function index(): string
    {
        $products = Product::active()->latest()->get();
        return $this->view('products/index.twig', compact('products'));
    }
}
```

### 3. Agregar una ruta

```php
// app/routes/web.php
$router->get('/products', [ProductController::class, 'index'], [Route::BEFORE => 'auth']);
```

### 4. Registrar un servicio o listener

```php
// app/providers.php
use Core\Events\EventDispatcher;
use Core\Contracts\MailerInterface;

// Listener de evento
EventDispatcher::listen(UserLoggedIn::class, function (UserLoggedIn $e): void {
    // Enviar notificación, limpiar caché, etc.
});

// Implementación del mailer
ServicesContainer::bind(MailerInterface::class, fn() => new SmtpMailer(
    host: $_ENV['MAIL_HOST'],
    port: (int) $_ENV['MAIL_PORT'],
    user: $_ENV['MAIL_USER'],
    pass: $_ENV['MAIL_PASS'],
));
```

### 5. Validar un formulario

```php
// app/Http/Requests/ProductRequest.php
namespace App\Http\Requests;

use App\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'  => ['required', 'max:150'],
            'price' => ['required', 'numeric'],
        ];
    }
}

// En el controlador:
$form = ProductRequest::fromRequest();
if ($form->fails()) {
    OldInput::flash($form->only(['name', 'price']), $form->fieldErrors());
    Flash::set('error', $form->firstError());
    $this->back('/products/create');
}
```

---

## Seguridad

### WAF — 7 capas de protección

1. **Whitelist** — IPs / sesiones admin / cookie de bypass con HMAC
2. **Fast Ban** — Redis (si disponible) para corte inmediato sin DB
3. **Identidad** — Rate limiting, bloqueo de IP y fingerprint de navegador
4. **Reputación** — Detección de bots, scrapers, infraestructura cloud
5. **Comportamiento** — Análisis de navegación no humana, inferencia de IA
6. **Contenido** — SQL injection, XSS, XXE, path traversal, command injection
7. **Persistencia** — Detección de fuzzing por patrones acumulados

### Otras medidas

- **CSRF** — Token en sesión, comparación con `hash_equals()` (timing-safe)
- **Sesión** — `HttpOnly`, `SameSite=Lax`, regeneración de ID tras login
- **Contraseñas** — bcrypt cost 12, bloqueo a los 5 intentos (30 min)
- **Reset tokens** — `random_bytes(32)` → hex 64 chars, SHA-256 en BD, TTL 60 min
- **Validación** — `filter_var` email, longitudes máximas, formato hextoken
- **Headers** — Security headers inyectados por el WAF en cada respuesta

---

## Componentes Twig

```twig
{% import 'components/alert.twig'      as alert %}
{% import 'components/badge.twig'      as badge %}
{% import 'components/button.twig'     as btn %}
{% import 'components/input.twig'      as input %}
{% import 'components/stat_card.twig'  as stat %}
{% import 'components/empty_state.twig' as empty %}

{{ alert.render('success', 'Operación exitosa.')|raw }}
{{ badge.render('Activo', 'success')|raw }}
{{ btn.render('Guardar', 'primary', 'submit')|raw }}
{{ input.render('email', 'Correo', 'email', old.email, errors.email)|raw }}
{{ stat.render('Usuarios', total_users, 'sky')|raw }}
{{ empty.render('Sin resultados', 'No hay registros.', 'Crear primero', '/create')|raw }}
```

### Variables globales en Twig

| Variable | Valor |
|---|---|
| `app_name` | Nombre de la empresa (`.env → EMPRESA_NOMBRE`) |
| `base_url` | URL base del sistema |
| `auth_role` | Rol del usuario autenticado |
| `auth_user_name` | Nombre del usuario autenticado |
| `_uri` | URI actual (para nav activo) |
| `old` | Valores POST anteriores (tras validación fallida) |
| `errors` | Errores por campo (tras validación fallida) |
| `session_lifetime` | Minutos de vida de la sesión (para modal de timeout) |

---

## Audit Log

El trait `Auditable` registra automáticamente create/update/delete de cualquier modelo:

```php
use App\Models\Concerns\Auditable;

class User extends BaseModel
{
    use Auditable;

    protected array $auditExclude = ['password', 'reset_token'];
}
```

Para registrar eventos manuales:

```php
AuditLog::record(
    action:  'invoice.approved',
    entity:  $invoice,
    userId:  Auth::id(),
);
```

El panel `/admin/audit-log` (solo rol `admin`) muestra el historial con filtros por acción y usuario, y un diff visual JSON para ver qué cambió.

---

## Comandos útiles

```bash
# Análisis estático
composer analyze

# Formateo de código
composer format

# Tests
composer test

# Reset de base de datos
php database/migrate.php --fresh
```

---

## Dependencias

| Paquete | Versión | Propósito |
|---|---|---|
| `illuminate/database` | ^13.3 | Eloquent ORM standalone |
| `illuminate/events` | ^13.3 | Eventos de Eloquent (model hooks) |
| `illuminate/pagination` | ^13.3 | Paginación de colecciones |
| `twig/twig` | ^3.0 | Motor de plantillas |
| `phroute/phroute` | ^2.2 | Router con filtros before/after |
| `monolog/monolog` | ^3.10 | Logging multicanal |
| `vlucas/phpdotenv` | ^5.6 | Carga de variables de entorno |

---

## Licencia

MIT © [Noe Cazarez Camargo](mailto:suzuma@gmail.com) — SuZuMa