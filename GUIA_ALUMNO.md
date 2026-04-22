# Guía del Alumno — Taller Práctico: Desarrollo de un Framework PHP

**Taller:** Construcción de aplicaciones web con arquitectura propia en PHP 8.3
**Duración:** 5 sesiones de 3 horas
**Framework de trabajo:** SZM-Core

---

## ¿Qué vas a construir?

Durante este taller vas a trabajar directamente sobre el código fuente de un framework PHP real. No usarás Laravel ni Symfony: entenderás y modificarás las piezas de las que esos frameworks están hechos.

Al terminar las 5 sesiones habrás:

- Trazado el flujo completo de una petición HTTP desde el navegador hasta la respuesta.
- Añadido rutas, controladores y vistas propias al framework.
- Creado migraciones de base de datos y modelos con relaciones Eloquent.
- Probado en vivo cómo el WAF detecta y bloquea ataques.
- Escrito tests unitarios con PHPUnit 11.
- Preparado la aplicación para un entorno de producción.

---

## Lo que necesitas antes de comenzar

### Software requerido

| Herramienta | Versión mínima | Instalación |
|---|---|---|
| PHP | 8.3 | php.net/downloads |
| Composer | 2.x | getcomposer.org |
| MySQL | 8.0 | dev.mysql.com |
| Git | 2.x | git-scm.com |
| VS Code o PhpStorm | Reciente | editor de tu preferencia |
| curl | Cualquiera | ya incluido en macOS y Linux |

### Verificar que todo está listo

```bash
php --version      # debe mostrar PHP 8.3.x o superior
composer --version # debe mostrar Composer 2.x
mysql --version    # debe mostrar MySQL 8.x
git --version      # debe mostrar git version 2.x
curl --version     # debe mostrar curl 7.x o superior
```

Si alguno falla, instálalo antes de continuar.

### Conocimiento previo esperado

No es necesario haber usado Laravel o Symfony antes. Sí se espera que puedas:

- Escribir una clase PHP con propiedades, métodos y herencia.
- Escribir una consulta SQL básica (`SELECT`, `INSERT`, `WHERE`).
- Entender qué es una petición HTTP (URL, método GET/POST, código de respuesta).
- Usar la terminal para ejecutar comandos.

---

## Cómo está organizado el proyecto

```
szm-core/
├── app/                  ← Tu código: controladores, modelos, vistas, rutas
│   ├── Controllers/      ← Manejadores de peticiones HTTP
│   ├── Models/           ← Modelos Eloquent (tablas de la BD)
│   ├── Services/         ← Lógica de negocio compleja
│   ├── Helpers/          ← Funciones auxiliares (Flash, OldInput)
│   ├── Events/           ← Eventos de la aplicación
│   ├── Views/            ← Plantillas Twig (.twig)
│   ├── routes/web.php    ← Definición de todas las rutas
│   ├── filters.php       ← Middleware (auth, guest, csrf, admin)
│   └── providers.php     ← Registro de servicios y listeners
│
├── core/                 ← El motor del framework (no modificar a menos que se indique)
│   ├── Bootstrap/        ← Secuencia de arranque de la aplicación
│   ├── Security/Waf/     ← Web Application Firewall
│   ├── Auth/             ← Autenticación y sesiones
│   ├── Database/         ← Inicialización de Eloquent ORM
│   ├── Http/             ← Request, StaticFileHandler
│   └── ServicesContainer.php ← Contenedor de dependencias
│
├── database/
│   ├── migrate.php       ← Script para crear/reiniciar las tablas
│   ├── migrations/       ← Archivos SQL numerados (001, 002...)
│   └── seeds/            ← Datos iniciales (usuario admin)
│
├── tests/                ← Tests automáticos del WAF
├── storage/              ← Logs, caché, uploads (generado en runtime)
├── public/               ← CSS, JS, imágenes accesibles desde el navegador
├── index.php             ← Punto de entrada único (front controller)
├── config.php            ← Configuración de la app (lee de .env)
└── .env                  ← Variables de entorno (¡nunca subir a Git!)
```

> **Regla de oro:** Tu trabajo va en `app/`. Solo tocas `core/` cuando el ejercicio lo indica explícitamente.

---

## Referencia rápida de comandos

```bash
composer serve              # Inicia el servidor en localhost:8000
composer test               # Ejecuta todos los tests del WAF
composer analyze            # Análisis estático con PHPStan
composer format             # Formatea el código con PHP-CS-Fixer

php database/migrate.php             # Crea tablas y ejecuta seeds
php database/migrate.php --no-seed   # Solo crea tablas
php database/migrate.php --fresh     # DROP + recrear desde cero ⚠️ borra todos los datos
```

---

---

# SESIÓN 1 — Arquitectura y bootstrap

## ¿Qué aprenderás?

- Por qué todas las peticiones pasan por un solo archivo (`index.php`).
- Qué hace el framework en cada uno de sus 9 pasos de arranque.
- Cómo configurar el entorno con `.env`.
- Cómo añadir un paso personalizado al bootstrap.

---

## Concepto 1 — El patrón Front Controller

Cuando escribes una URL en el navegador, el servidor podría ejecutar un archivo PHP diferente para cada ruta. Eso era lo habitual en los años 2000 y generaba proyectos imposibles de mantener.

Un **Front Controller** centraliza todo: una sola puerta de entrada recibe _todas_ las peticiones y decide qué hacer con cada una.

```
Antes (sin front controller):
  /login.php      → ejecuta login.php directamente
  /perfil.php     → ejecuta perfil.php directamente
  /admin/users.php → ejecuta admin/users.php directamente

Con front controller:
  /login          → index.php → decide → AuthController::loginForm()
  /perfil         → index.php → decide → PerfilController::show()
  /admin/users    → index.php → decide → UserController::index()
```

Abre `index.php` y lee su contenido. Es muy corto: básicamente hace una sola cosa.

---

## Concepto 2 — Los 9 pasos del bootstrap

Abre `core/Bootstrap/Application.php` y busca el método `boot()`. Vas a encontrar esto:

```php
$app->blockSensitiveUris();  // 0. Bloquea .env, .git, .sql antes de todo
$app->loadEnvironment();     // 1. Carga el archivo .env
$app->loadConfig();          // 2. Carga config.php
$app->configurePhpRuntime(); // 3. Ajusta timezone, error_reporting
$app->defineConstants();     // 4. Define _BASE_PATH_, _BASE_HTTP_, etc.
$app->initializeDatabase();  // 5. Conecta con MySQL vía Eloquent
$app->guardSystemState();    // 6. Verifica modo mantenimiento
$app->runWaf();              // 7. Ejecuta el WAF de seguridad
$app->startSession();        // 8. Inicia sesión PHP + token CSRF
$app->writeAccessLog();      // 9. Registra la petición en el log
```

**¿Por qué importa el orden?** Cada paso depende del anterior. Intenta responder:

- ¿Puedes conectar a la BD antes de cargar `.env`? ¿Por qué no?
- ¿Puedes ejecutar el WAF antes de inicializar la BD? ¿Qué pasaría?
- ¿Por qué `blockSensitiveUris()` es el paso CERO, antes de cargar `.env`?

---

## Ejercicio 1A — Instalar y levantar el framework

```bash
# 1. Clonar el repositorio
git clone <url-del-repositorio> szm-taller
cd szm-taller

# 2. Instalar dependencias PHP
composer install

# 3. Crear tu archivo de entorno
cp .env.example .env
```

Ahora abre `.env` en tu editor y configura las credenciales de tu base de datos local:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=szm_core
DB_USERNAME=root
DB_PASSWORD=tu_contraseña
```

```bash
# 4. Crear las tablas e insertar el usuario admin
php database/migrate.php
# Te pedirá email y contraseña para el admin — anótalos

# 5. Levantar el servidor de desarrollo
composer serve
```

Abre el navegador en `http://localhost:8000/login`. Deberías ver el formulario de autenticación. Inicia sesión con las credenciales del admin que acabas de crear.

**Verificación:** si ves el dashboard, el framework está funcionando correctamente.

---

## Ejercicio 1B — Trazar el flujo de una petición

Dibuja (en papel o en cualquier herramienta) el diagrama de secuencia de una petición `GET /login`. Debe mostrar:

```
Browser
  └─▶ index.php
        └─▶ Application::boot()
              └─▶ [paso 1 a 9 en orden]
                    └─▶ Router
                          └─▶ AuthController::loginForm()
                                └─▶ Twig → renderiza login.twig
                                      └─▶ HTML al navegador
```

Para cada uno de estos escenarios, marca en qué paso exactamente se detiene la ejecución:

| Escenario | Se detiene en el paso... |
|---|---|
| La base de datos está caída | |
| El archivo `.env` no existe | |
| La URL es `http://localhost:8000/.env` | |
| El usuario no está autenticado y pide `/admin/users` | |

---

## Ejercicio 1C — Añadir un paso al bootstrap ⭐

Vas a añadir el paso 10 al bootstrap: registrar cada petición en un archivo de log propio.

**Objetivo:** que el archivo `storage/logs/boot.log` crezca con una línea nueva por cada petición recibida. La línea debe tener este formato:

```
[2026-04-21 10:35:02] GET /login :: 127.0.0.1
```

**Instrucciones:**

1. Abre `core/Bootstrap/Application.php`.
2. Crea un método privado llamado `logRequest()` al final de la clase:

```php
private function logRequest(): void
{
    $line = sprintf(
        "[%s] %s %s :: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $this->uri,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );
    file_put_contents(
        __DIR__ . '/../../storage/logs/boot.log',
        $line,
        FILE_APPEND | LOCK_EX
    );
}
```

3. Llama a ese método al final de `boot()`, después de `writeAccessLog()`.
4. Verifica que `storage/logs/boot.log` existe y crece con cada petición.

**Criterio de éxito:** navegar a tres URLs diferentes y ver tres líneas distintas en el log.

---

## Pregunta de reflexión — Sesión 1

Responde por escrito antes de la siguiente sesión:

1. ¿Cuál es la diferencia entre un filtro `before` y un filtro `after` en el contexto del router?
2. ¿Qué devuelve un filtro para "cortar" la ejecución antes de llegar al controlador?
3. ¿Por qué la ruta `/logout` usa `POST` y no `GET`? *(Pista: piensa en qué pasa si un `<img src="/logout">` está embebido en otra página.)*

---

---

# SESIÓN 2 — Router, controladores y vistas

## ¿Qué aprenderás?

- Cómo registrar rutas protegidas con filtros.
- Cómo implementar un controlador con los métodos necesarios.
- Cómo crear plantillas Twig con herencia de layouts.
- El patrón **PRG** (Post → Redirect → Get) para evitar reenvíos de formulario.

---

## Concepto 1 — Rutas y filtros

Abre `app/routes/web.php`. Observa que las rutas no se registran sueltas, sino en grupos con filtros:

```php
// Grupo protegido: solo usuarios no autenticados pueden entrar
$router->group([Route::BEFORE => 'guest'], function ($router): void {
    $router->get('/login',  [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);
});

// Ruta individual protegida: solo usuarios autenticados
$router->get('/', [HomeController::class, 'index'], [Route::BEFORE => 'auth']);
```

Los filtros están definidos en `app/filters.php`. Lee ese archivo y completa esta tabla:

| Filtro | ¿Qué verifica? | ¿Qué hace si falla? |
|---|---|---|
| `auth` | | |
| `guest` | | |
| `csrf` | | |
| `admin` | | |

---

## Concepto 2 — El BaseController

Todos tus controladores extienden `BaseController`. Este te da cuatro helpers que usarás constantemente:

```php
// Renderiza una plantilla Twig
return $this->view('carpeta/archivo.twig', ['variable' => $valor]);

// Redirige a otra URL (termina la ejecución)
return $this->redirect('/dashboard');

// Redirige a la página anterior
return $this->back('/fallback');

// Responde con JSON (para APIs o AJAX)
return $this->json(['ok' => true, 'id' => $nuevo_id]);

// Aborta con un código HTTP (muestra views/errors/404.twig si existe)
$this->abort(404, 'Página no encontrada');
```

---

## Concepto 3 — Herencia de layouts en Twig

Twig usa herencia para evitar repetir el HTML común (cabecera, nav, footer). El archivo base define bloques que los hijos reemplazan:

```twig
{# app/Views/layouts/base.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}SZM{% endblock %}</title>
</head>
<body>
    {% block content %}{% endblock %}
</body>
</html>
```

```twig
{# Tu vista hereda el layout #}
{% extends 'layouts/base.twig' %}

{% block title %}Mi Página{% endblock %}

{% block content %}
    <h1>Hola, {{ auth_user.name }}</h1>
{% endblock %}
```

Estas variables están disponibles en **todas** las vistas automáticamente:

| Variable | Contenido |
|---|---|
| `auth_user` | Usuario autenticado (objeto `User`) o `null` |
| `app_name` | Valor de `EMPRESA_NOMBRE` en `.env` |
| `base_url` | URL base de la aplicación |
| `_uri` | URI actual |

---

## Ejercicio 2 — Módulo de Perfil de Usuario

Vas a implementar un módulo en `/perfil` que permita al usuario ver y actualizar su nombre.

### Paso 1 — Añadir las rutas

En `app/routes/web.php`, añade esto **dentro** del bloque de rutas autenticadas:

```php
$router->get('/perfil',  [PerfilController::class, 'show'],   [Route::BEFORE => 'auth']);
$router->post('/perfil', [PerfilController::class, 'update'], [Route::BEFORE => 'auth']);
```

Recuerda añadir el `use` correspondiente al inicio del archivo.

### Paso 2 — Crear el controlador

Crea `app/Controllers/PerfilController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth\Auth;
use App\Helpers\Flash;

final class PerfilController extends BaseController
{
    public function show(): string
    {
        return $this->view('perfil/show.twig');
    }

    public function update(): never
    {
        $nombre = trim($_POST['nombre'] ?? '');

        // Validación
        if ($nombre === '') {
            Flash::set('error', 'El nombre no puede estar vacío.');
            Flash::setOld(['nombre' => $nombre]);
            $this->redirect('/perfil');
        }

        if (strlen($nombre) > 80) {
            Flash::set('error', 'El nombre no puede superar 80 caracteres.');
            Flash::setOld(['nombre' => $nombre]);
            $this->redirect('/perfil');
        }

        // Guardar
        Auth::user()->update(['name' => $nombre]);

        Flash::set('success', 'Perfil actualizado correctamente.');
        $this->redirect('/perfil');
    }
}
```

### Paso 3 — Crear la vista

Crea `app/Views/perfil/show.twig`. La vista debe:

- Heredar del layout base.
- Mostrar un formulario con el campo `nombre` pre-llenado.
- Mostrar el email del usuario (solo lectura, no editable).
- Mostrar el rol del usuario.
- Mostrar mensajes de error o éxito si existen.

```twig
{% extends 'layouts/app.twig' %}

{% block title %}Mi Perfil{% endblock %}

{% block content %}
<div class="container">
    <h2>Mi Perfil</h2>

    {# Mensajes flash #}
    {% set error = flash('error') %}
    {% set success = flash('success') %}
    {% if error %}<div class="alert alert-danger">{{ error }}</div>{% endif %}
    {% if success %}<div class="alert alert-success">{{ success }}</div>{% endif %}

    <form method="POST" action="/perfil">
        {{ csrf_field()|raw }}

        <div class="form-group">
            <label>Nombre</label>
            <input type="text"
                   name="nombre"
                   value="{{ old('nombre', auth_user.name) }}"
                   class="form-control"
                   maxlength="80">
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="text" value="{{ auth_user.email }}" class="form-control" disabled>
        </div>

        <div class="form-group">
            <label>Rol</label>
            <input type="text" value="{{ auth_user.role.name ?? 'Sin rol' }}" class="form-control" disabled>
        </div>

        <button type="submit" class="btn btn-primary">Guardar cambios</button>
    </form>
</div>
{% endblock %}
```

### Paso 4 — Verificar

Prueba estos escenarios uno por uno:

| Acción | Resultado esperado |
|---|---|
| `GET /perfil` sin sesión | Redirige a `/login` |
| `GET /perfil` con sesión | Muestra el formulario con el nombre actual |
| `POST /perfil` con nombre válido | Redirige a `/perfil` con mensaje de éxito |
| `POST /perfil` con nombre vacío | Muestra el error "El nombre no puede estar vacío" |
| Recargar la página después del POST exitoso | No reenvía el formulario (patrón PRG) |

> **¿Por qué PRG?** Si el usuario actualiza y recarga la página, el navegador preguntaría "¿reenviar datos del formulario?". El redirect evita eso porque la recarga hace un GET, no un POST.

---

## Pregunta de reflexión — Sesión 2

Responde antes de la siguiente sesión:

1. ¿Por qué el formulario necesita `{{ csrf_field()|raw }}`? ¿Qué pasa si lo omites e intentas hacer POST?
2. ¿Qué diferencia hay entre `{id}` y `{id:i}` en la definición de una ruta?
3. Si quisiera añadir un filtro que verificara que el usuario tiene activado 2FA, ¿dónde lo registraría y qué devolvería si no lo tiene?

---

---

# SESIÓN 3 — Base de datos, modelos y RBAC

## ¿Qué aprenderás?

- Cómo crear migraciones SQL y ejecutarlas con el runner del framework.
- Cómo definir modelos Eloquent con relaciones, scopes y mutators.
- Qué es RBAC y cómo está implementado en las tablas del framework.
- Cómo registrar cambios en el audit log.

---

## Concepto 1 — Eloquent sin Laravel

El framework usa Eloquent ORM directamente, sin el resto de Laravel. Esto se configura en `core/Database/DbContext.php` usando `Illuminate\Database\Capsule\Manager`.

Tus modelos extienden `BaseModel`, que a su vez extiende `Illuminate\Database\Eloquent\Model`. Eso significa que tienes acceso a toda la API de Eloquent:

```php
// Buscar por ID
User::find(1);

// Buscar con condiciones
User::where('active', true)->get();

// Crear un registro
Category::create(['name' => 'Tecnología', 'slug' => 'tecnologia']);

// Actualizar
$user->update(['name' => 'Nuevo nombre']);

// Eliminar
$category->delete();

// Paginación
Category::paginate(10);

// Relaciones
$user->role;           // BelongsTo — devuelve el Role del usuario
$role->users;          // HasMany — devuelve todos los Users de ese rol
```

---

## Concepto 2 — RBAC: Roles y Permisos

El framework implementa **Role-Based Access Control** con estas tablas:

```
szm_roles
  id | name  | description
  1  | admin | Administrador del sistema
  2  | user  | Usuario estándar

szm_permissions
  id | name         | description
  1  | users.view   | Ver usuarios
  2  | users.create | Crear usuarios
  ...

szm_role_permissions
  role_id | permission_id
  1       | 1              ← admin puede ver usuarios
  1       | 2              ← admin puede crear usuarios
  2       | 1              ← user solo puede ver
```

Esto significa que para controlar acceso nunca hardcodeas `if ($user->id === 1)`. En su lugar:

```php
// Verificar rol
if (Auth::user()->hasRole('admin')) { ... }

// Verificar con el filtro de ruta (recomendado)
$router->get('/admin/x', [...], [Route::BEFORE => 'admin']);
```

---

## Concepto 3 — Migraciones numeradas

Las migraciones en `database/migrations/` son archivos SQL numerados. El runner (`database/migrate.php`) los ejecuta en orden numérico. **Nunca modifiques un archivo de migración ya ejecutado**: crea uno nuevo con el número siguiente.

```
001_szm_roles.sql              → tabla de roles
002_szm_permissions.sql        → tabla de permisos
003_szm_users.sql              → tabla de usuarios
004_szm_role_permissions.sql   → relación roles-permisos
005_szm_audit_log.sql          → registro de auditoría
006_waf_tables.sql             → tablas del WAF
007_waf_performance_indexes.sql → índices de rendimiento
008_szm_categories.sql         → tu nueva migración ← aquí vas tú
```

---

## Ejercicio 3 — Módulo de Categorías

### Paso 1 — Migración

Crea `database/migrations/008_szm_categories.sql`:

```sql
CREATE TABLE IF NOT EXISTS szm_categories (
    id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)     NOT NULL,
    slug       VARCHAR(100)     NOT NULL UNIQUE,
    active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at TIMESTAMP        NULL DEFAULT NULL,
    updated_at TIMESTAMP        NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Ejecuta la migración:

```bash
php database/migrate.php
```

Verifica en MySQL:

```bash
mysql -u root -p szm_core -e "DESCRIBE szm_categories;"
```

### Paso 2 — Modelo

Crea `app/Models/Category.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Category extends BaseModel
{
    protected $table    = 'szm_categories';
    protected $fillable = ['name', 'slug', 'active'];

    protected $casts = [
        'active'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Scope: Category::active()->get()  → solo las activas
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    // Mutator: al asignar 'name', genera el slug automáticamente
    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['slug'] = strtolower(
            str_replace([' ', 'á','é','í','ó','ú','ñ'],
                        ['-','a','e','i','o','u','n'], $value)
        );
    }
}
```

### Paso 3 — Controlador

Crea `app/Controllers/CategoryController.php` con tres métodos:

**`index()`** — lista las categorías con paginación:
```php
public function index(): string
{
    $categories = Category::orderBy('name')->paginate(10);
    return $this->view('admin/categories/index.twig', compact('categories'));
}
```

**`store()`** — crea una categoría nueva:
```php
public function store(): never
{
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        Flash::set('error', 'El nombre es requerido.');
        $this->redirect('/admin/categories');
    }

    Category::create(['name' => $name, 'active' => true]);

    Flash::set('success', "Categoría '{$name}' creada.");
    $this->redirect('/admin/categories');
}
```

**`destroy(int $id)`** — elimina por ID:
```php
public function destroy(int $id): never
{
    $category = Category::findOrFail($id);
    $category->delete();

    Flash::set('success', 'Categoría eliminada.');
    $this->redirect('/admin/categories');
}
```

### Paso 4 — Rutas

En `app/routes/web.php`, dentro del grupo `admin`:

```php
$router->get('/admin/categories',          [CategoryController::class, 'index']);
$router->post('/admin/categories',         [CategoryController::class, 'store']);
$router->delete('/admin/categories/{id:i}',[CategoryController::class, 'destroy']);
```

### Paso 5 — Vista básica

Crea `app/Views/admin/categories/index.twig`. Como mínimo debe mostrar:
- Un formulario para crear una categoría nueva (con `csrf_field()`).
- Una tabla con las categorías existentes (nombre, slug, activo, acciones).
- Un botón de eliminar por cada categoría (usa un mini-form con `method="POST"` y `<input name="_method" value="DELETE">`).
- Mensajes flash de error/éxito.

### Verificación con curl

```bash
# Primero obtén tu SESSION ID del navegador (F12 → Application → Cookies → SZM_SESSION)
SESSION="tu_session_id_aqui"
CSRF="tu_token_csrf_aqui"   # inspecciona el HTML del formulario

# Crear categoría
curl -X POST http://localhost:8000/admin/categories \
     -d "name=Tecnología&_token=${CSRF}" \
     -b "SZM_SESSION=${SESSION}" \
     -L   # sigue el redirect

# Verificar en BD
mysql -u root -p szm_core -e "SELECT * FROM szm_categories;"
```

---

## Pregunta de reflexión — Sesión 3

1. ¿Qué diferencia hay entre `save()` y `create()` en Eloquent?
2. ¿Qué pasa si intentas insertar dos categorías con el mismo nombre (slug duplicado)? ¿Cómo manejarías esa excepción?
3. ¿Por qué el `setNameAttribute()` en el modelo es preferible a hacer el procesamiento del slug en el controlador?

---

---

# SESIÓN 4 — Seguridad: CSRF, sesiones y WAF

> **Aviso importante:** Los ejercicios de esta sesión simulan ataques contra tu propio servidor local (`localhost`). Estos payloads **jamás** deben usarse contra sistemas de terceros sin autorización escrita. El propósito es entender cómo funciona la defensa.

## ¿Qué aprenderás?

- Por qué existen los tokens CSRF y cómo el framework los valida.
- La arquitectura de capas del WAF: detección, identidad, comportamiento.
- Cómo interpretar un log de ataque del WAF.
- Cómo configurar el WAF con variables de entorno.

---

## Concepto 1 — ¿Qué es CSRF?

Imagina que estás autenticado en tu banco. Abres otro tab y visitas una página maliciosa que tiene esto:

```html
<form action="https://mibanco.com/transferir" method="POST">
    <input name="destino" value="cuenta-del-atacante">
    <input name="monto"   value="10000">
</form>
<script>document.forms[0].submit();</script>
```

Tu navegador envía la petición con tus cookies de sesión válidas. El banco la procesa porque la sesión es legítima. Tu dinero desaparece.

**La solución:** un token secreto que solo existe en el formulario original. Si el atacante no conoce el token, su petición forjada es rechazada.

```php
// El token se genera una vez por sesión
$token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $token;

// Se incluye en el formulario
<input type="hidden" name="_token" value="<?= $token ?>">

// Se valida al recibir el POST
hash_equals($_SESSION['csrf_token'], $_POST['_token'])  // ✓ timing-safe
```

En Twig simplemente escribes `{{ csrf_field()|raw }}` dentro del `<form>` y el framework hace el resto.

---

## Concepto 2 — Arquitectura del WAF

El WAF ejecuta seis capas en orden. Si cualquier capa detecta algo sospechoso, bloquea la petición:

```
Petición entrante
  │
  ▼
┌─────────────────────────────────────────────┐
│ 1. blockForbiddenUris()                     │ → bloquea .env, .git, .sql, etc.
│ 2. isWhitelisted()                          │ → ¿la IP está en WAF_TRUSTED_IPS?
│ 3. detectAiBot()                            │ → sqlmap, nikto, burp, gobuster...
│ 4. checkBehavior()                          │ → demasiadas peticiones / anomalías
│ 5. detectAttack()                           │ → SQL injection, XSS, XXE, SSRF...
│ 6. evaluateScore()                          │ → ¿supera el umbral? → ban o block
└─────────────────────────────────────────────┘
  │
  ▼
Petición limpia → continúa al router
```

Cada ataque detectado suma puntos al **score de riesgo**. Al superar cierto umbral, la IP queda bloqueada en la BD.

---

## Ejercicio 4A — Laboratorio de ataques

Asegúrate de que el servidor está corriendo (`composer serve`). Ejecuta cada curl y completa la tabla:

```bash
# 1. Acceso a archivo sensible
curl -i "http://localhost:8000/.env"

# 2. SQL Injection clásica
curl -i "http://localhost:8000/login?user=%27+OR+%271%27%3D%271"

# 3. XSS reflejado
curl -i "http://localhost:8000/login?msg=%3Cscript%3Ealert(1)%3C%2Fscript%3E"

# 4. Path traversal
curl -i "http://localhost:8000/login?file=../../../../etc/passwd"

# 5. Herramienta de escaneo (User-Agent de sqlmap)
curl -i -A "sqlmap/1.7" "http://localhost:8000/"
```

| # | Payload | Código HTTP | Bloqueado en capa |
|---|---|---|---|
| 1 | `.env` | | |
| 2 | SQL Injection | | |
| 3 | XSS | | |
| 4 | Path traversal | | |
| 5 | sqlmap UA | | |

Para identificar en qué capa fue bloqueado, revisa:

```sql
-- En MySQL:
SELECT attack_type, score, uri, created_at
FROM waf_attack_logs_szm
ORDER BY created_at DESC
LIMIT 10;
```

---

## Ejercicio 4B — Configurar WAF_TRUSTED_IPS

1. Abre `.env` y añade: `WAF_TRUSTED_IPS=127.0.0.1`
2. Reinicia el servidor: `Ctrl+C` y `composer serve`
3. Repite el curl de SQL Injection del ejercicio anterior
4. Observa que **no** es bloqueada

Responde:
- ¿Para qué caso de uso real sirve `WAF_TRUSTED_IPS`?
- ¿Qué riesgo introduces si configuras mal esta variable?

5. Cuando termines, vuelve a dejarlo vacío: `WAF_TRUSTED_IPS=`

---

## Ejercicio 4C — Dashboard del WAF

1. Inicia sesión como admin: `http://localhost:8000/login`
2. Navega a `http://localhost:8000/admin/waf`
3. Responde estas preguntas basándote en lo que ves en pantalla:

| Pregunta | Tu respuesta |
|---|---|
| ¿Cuántos ataques detectó el WAF en la sesión de hoy? | |
| ¿Cuál fue el tipo de ataque más frecuente? | |
| ¿La propia IP (127.0.0.1) fue bloqueada en algún momento? | |
| ¿Qué información muestra cada entrada en "Attack Logs"? | |

4. Si tu IP fue bloqueada, ve a `admin/waf/blocked-ips` y desbloquéala usando el botón "Unban".

---

## Pregunta de reflexión — Sesión 4

1. Si un atacante conoce el valor de `WAF_BYPASS_SECRET`, ¿qué podría hacer?
2. ¿Por qué `blockForbiddenUris()` se ejecuta ANTES de `isWhitelisted()`? ¿Qué vulnerabilidad existiría si el orden fuera al revés?
3. ¿Por qué la validación CSRF usa `hash_equals()` en lugar de `===`?

---

---

# SESIÓN 5 — Testing y preparación para producción

## ¿Qué aprenderás?

- Cómo ejecutar la suite de tests del WAF con PHPUnit 11.
- Cómo escribir un test con `#[DataProvider]` para cubrir múltiples casos.
- Cómo interpretar un reporte de tests fallidos.
- Qué configuraciones son obligatorias antes de desplegar a producción.

---

## Concepto 1 — PHPUnit con atributos PHP 8

PHPUnit 11 usa atributos nativos de PHP 8 en lugar de anotaciones `@`:

```php
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

final class MiTest extends TestCase
{
    #[Test]                          // Este método es un test
    #[DataProvider('payloadsProvider')]  // Usa este método como fuente de datos
    public function detecta_ataque(string $payload): void
    {
        $probe = new WafDetectionProbe();
        $this->assertTrue($probe->detectSqlInjection($payload));
    }

    public static function payloadsProvider(): array
    {
        return [
            'union select'  => ["' UNION SELECT * FROM users--"],
            'or equals'     => ["' OR '1'='1'"],
            'drop table'    => ["'; DROP TABLE users;--"],
        ];
    }
}
```

Cuando usas `#[DataProvider]`, PHPUnit ejecuta el test **una vez por cada entrada** del array. Si falla alguna, te dice exactamente cuál.

---

## Concepto 2 — WafDetectionProbe

Los tests del WAF no instancian `Waf` directamente porque el WAF necesita base de datos, sesión y Redis. En su lugar se usa `WafDetectionProbe`, que expone solo los métodos de detección como métodos públicos sin dependencias externas:

```php
$probe = new WafDetectionProbe();

$probe->detectSqlInjection("' UNION SELECT 1--")  // true
$probe->detectXss("<script>alert(1)</script>")     // true
$probe->detectPathTraversal("../../../etc/passwd") // true
$probe->normalize("Hello%20%3Cworld%3E")           // "Hello <world>"
```

---

## Ejercicio 5A — Ejecutar la suite y analizar resultados

```bash
composer test
```

Deberías ver algo así:

```
PHPUnit 11.x

....................................................  60 / 222 ( 27%)
....................................................  120 / 222 ( 54%)
....................................................  180 / 222 ( 81%)
..........................................           222 / 222 (100%)

OK (222 tests, 222 assertions)
```

Si hay fallos, PHPUnit los lista así:

```
FAILED (failures: 2)

1) Tests\Waf\Detection\XssTest::detecta_xss with data set "html_escaped"
   Failed asserting that false is true.
   Expected: true
   Actual:   false
```

Para cada fallo que encuentres, registra:

| Test que falla | ¿Qué esperaba? | ¿Qué obtuvo? | Hipótesis de la causa |
|---|---|---|---|
| | | | |

---

## Ejercicio 5B — Escribir tu propio DataProvider ⭐

Crea `tests/Waf/Detection/PathTraversalExtendedTest.php`.

**Requisitos:**
- Mínimo 5 variantes de path traversal que el WAF debería detectar (investiga variantes con URL encoding, doble encoding, null bytes, etc.).
- Mínimo 3 rutas legítimas que **no** deben ser detectadas como ataque.
- Todos los tests deben pasar con `composer test`.

```php
<?php
declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Waf\WafDetectionProbe;

final class PathTraversalExtendedTest extends TestCase
{
    private WafDetectionProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new WafDetectionProbe();
    }

    #[Test]
    #[DataProvider('maliciousPathProvider')]
    public function detecta_path_traversal(string $payload): void
    {
        $this->assertTrue(
            $this->probe->detectPathTraversal($payload),
            "Se esperaba detección para: {$payload}"
        );
    }

    #[Test]
    #[DataProvider('safePathProvider')]
    public function no_genera_falso_positivo(string $payload): void
    {
        $this->assertFalse(
            $this->probe->detectPathTraversal($payload),
            "Falso positivo para ruta legítima: {$payload}"
        );
    }

    public static function maliciousPathProvider(): array
    {
        return [
            // Añade aquí tus 5 variantes maliciosas
            // Ejemplo de formato:
            // 'descripcion' => ['payload'],
        ];
    }

    public static function safePathProvider(): array
    {
        return [
            // Añade aquí tus 3 rutas legítimas
        ];
    }
}
```

**Pista para encontrar variantes:** busca en OWASP Testing Guide la sección "Testing for Path Traversal".

---

## Ejercicio 5C — Checklist de producción

Antes de desplegar cualquier aplicación web, debes verificar esta lista. Complétala para tu instalación local:

| # | Verificación | Comando para comprobar | ✓ / ✗ |
|---|---|---|---|
| 1 | `APP_ENV=prod` en `.env` | `grep APP_ENV .env` | |
| 2 | `SESSION_SECURE=true` en `.env` | `grep SESSION_SECURE .env` | |
| 3 | `APP_URL` con dominio real | `grep APP_URL .env` | |
| 4 | `WAF_TRUSTED_IPS` vacío o con IPs específicas | `grep WAF_TRUSTED_IPS .env` | |
| 5 | `.env` devuelve 403 | `curl -i http://localhost:8000/.env` | |
| 6 | `composer.json` devuelve 403 | `curl -i http://localhost:8000/composer.json` | |
| 7 | `/vendor/` devuelve 403 | `curl -i http://localhost:8000/vendor/autoload.php` | |
| 8 | `storage/logs/` tiene permisos correctos | `ls -la storage/` | |
| 9 | El directorio `/storage` no es accesible vía HTTP | `curl -i http://localhost:8000/storage/` | |
| 10 | No se muestran errores PHP en pantalla (solo en log) | Visitar una URL inexistente | |

Para cada punto que marques con ✗, anota qué cambio necesitas hacer para corregirlo.

---

## Pregunta de reflexión final — Sesión 5

1. ¿Qué diferencia hay entre un test que falla porque el código tiene un bug y uno que falla porque el test está mal escrito?
2. ¿Por qué `WafDetectionProbe` existe en lugar de testear `Waf` directamente?
3. Menciona dos cosas que cambiarías en el código del framework si tuvieras que mantenerlo en producción durante un año.

---

---

# Glosario rápido

| Término | Definición |
|---|---|
| **Front Controller** | Patrón donde todas las peticiones HTTP pasan por un único punto de entrada |
| **PSR-4** | Estándar de autoloading: el namespace refleja la estructura de directorios |
| **Autoloading** | PHP carga automáticamente las clases cuando las necesita, sin `require` manual |
| **Eloquent ORM** | Librería que mapea filas de BD a objetos PHP y viceversa |
| **Migration** | Script SQL versionado que describe cómo debe estar la estructura de la BD |
| **RBAC** | Role-Based Access Control: los permisos se asignan a roles, los roles a usuarios |
| **CSRF** | Cross-Site Request Forgery: ataque que engaña al navegador para enviar peticiones no deseadas |
| **XSS** | Cross-Site Scripting: inyección de código JavaScript malicioso en páginas web |
| **SQL Injection** | Inyección de código SQL malicioso en consultas a la base de datos |
| **WAF** | Web Application Firewall: capa de seguridad que filtra peticiones maliciosas |
| **PRG** | Post-Redirect-Get: patrón para evitar el reenvío de formularios al recargar |
| **DataProvider** | Método estático de PHPUnit que provee múltiples casos de prueba para un test |
| **Twig** | Motor de plantillas PHP con herencia, escapado automático y helpers |
| **DotEnv** | Librería que carga variables de entorno desde un archivo `.env` |
| **Score de riesgo** | Puntuación que acumula el WAF por cada comportamiento sospechoso detectado |

---

# Registro de trabajo personal

Usa esta sección para llevar un control de tu avance:

| Sesión | Ejercicio | Estado | Notas |
|---|---|---|---|
| 1 | Instalación y verificación | | |
| 1 | Diagrama de flujo | | |
| 1 | Añadir `logRequest()` al bootstrap | | |
| 2 | Módulo de Perfil (show) | | |
| 2 | Módulo de Perfil (update + PRG) | | |
| 3 | Migración `szm_categories` | | |
| 3 | Modelo `Category` con scope y mutator | | |
| 3 | CRUD de categorías (index, store, destroy) | | |
| 4 | Laboratorio de ataques (tabla completada) | | |
| 4 | Configurar y probar `WAF_TRUSTED_IPS` | | |
| 4 | Análisis del dashboard del WAF | | |
| 5 | Ejecutar suite y analizar resultados | | |
| 5 | `PathTraversalExtendedTest` con DataProvider | | |
| 5 | Checklist de producción completado | | |