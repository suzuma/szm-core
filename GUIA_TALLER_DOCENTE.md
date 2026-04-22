# Guía del Docente — Taller Práctico: Desarrollo de un Framework PHP desde Cero

**Asignatura sugerida:** Desarrollo de Software / Arquitectura de Aplicaciones Web
**Nivel:** Licenciatura (7.º – 8.º semestre) o Ingeniería en Sistemas
**Duración total:** 5 sesiones de 3 horas (15 horas presenciales + ~10 horas de trabajo autónomo)
**Proyecto de referencia:** SZM-Core — framework PHP minimalista con WAF integrado

---

## Propósito de esta guía

Este documento está diseñado exclusivamente para el docente. Describe los objetivos pedagógicos de cada sesión, la secuencia de actividades, las preguntas de facilitación recomendadas, los puntos de control y los criterios de evaluación. Los estudiantes trabajan directamente sobre el código fuente del framework.

---

## Perfil del estudiante objetivo

| Conocimiento previo esperado | Nivel requerido |
|---|---|
| PHP orientado a objetos (clases, herencia, interfaces) | Intermedio |
| SQL y MySQL | Básico-intermedio |
| HTTP (verbos, cabeceras, códigos de estado) | Básico |
| Uso de Composer y autoloading PSR-4 | Básico |
| Control de versiones con Git | Básico |

**No se requiere** conocimiento previo de frameworks (Laravel, Symfony), seguridad web ni patrones de diseño avanzados. El taller los introduce de forma progresiva.

---

## Herramientas necesarias (preparar antes del taller)

```
PHP 8.3+           → php.net/downloads
Composer           → getcomposer.org
MySQL 8.0+         → dev.mysql.com
Git                → git-scm.com
VS Code o PhpStorm → editor con soporte PHP
Postman / curl     → para pruebas HTTP manuales
```

> **Consejo para el docente:** Prepara una imagen Docker o una máquina virtual pre-configurada con todo lo anterior para evitar perder tiempo en instalaciones durante la primera sesión. Alternativamente, usa GitHub Codespaces apuntando al repositorio.

---

## Visión general del taller

```
SESIÓN 1 ── Arquitectura y bootstrap
SESIÓN 2 ── Router, controladores y vistas (Twig)
SESIÓN 3 ── Base de datos, modelos (Eloquent) y RBAC
SESIÓN 4 ── Seguridad: CSRF, sesiones y WAF
SESIÓN 5 ── Testing, auditoría y despliegue
```

Cada sesión tiene la misma estructura interna:

1. **Kick-off** (15 min) — exposición conceptual breve
2. **Exploración guiada** (30 min) — docente recorre el código en vivo
3. **Ejercicio central** (90 min) — estudiantes implementan/modifican
4. **Revisión de pares** (20 min) — discusión entre equipos
5. **Cierre y siguiente paso** (25 min) — correcciones, Q&A, tarea

---

## SESIÓN 1 — Arquitectura y bootstrap

### Objetivos de aprendizaje

Al finalizar esta sesión el estudiante será capaz de:
- Describir el patrón Front Controller y por qué centraliza el manejo de peticiones.
- Trazar el flujo completo desde que llega una petición HTTP hasta que se genera la respuesta.
- Identificar la responsabilidad de cada paso del método `Application::boot()`.
- Configurar el entorno del framework (`.env`, Composer, base de datos).

### Conceptos clave a introducir

| Concepto | Archivo de referencia | Tiempo sugerido |
|---|---|---|
| Front Controller | `index.php` | 5 min |
| Autoloading PSR-4 | `composer.json` sección `autoload` | 5 min |
| Variables de entorno con `.env` | `.env.example` | 5 min |
| Secuencia de bootstrap de 9 pasos | `core/Bootstrap/Application.php` método `boot()` | 10 min |

### Exploración guiada (abrir en el editor junto con los estudiantes)

```
index.php           → punto de entrada, llama a Application::boot(__DIR__)
Application::boot() → leer los 9 pasos en orden
.env.example        → mostrar todas las secciones (APP, DB, SESSION, WAF)
composer.json       → mostrar namespaces App\ y Core\
```

**Pregunta de facilitación:** "Si quisiéramos añadir un paso 10 al bootstrap —por ejemplo, conectar a un servicio de caché— ¿dónde exactamente lo añadirías y por qué importa el orden?"

### Ejercicio central — Levantar y explorar el framework

**Duración:** 90 minutos
**Modalidad:** Individual o en parejas

**Parte A — Instalación (30 min)**

```bash
git clone <repositorio> szm-taller
cd szm-taller
composer install
cp .env.example .env
# Editar .env con credenciales reales de la BD
php database/migrate.php
composer serve
```

Verificar que `http://localhost:8000/login` muestra el formulario de autenticación.

**Parte B — Análisis de flujo (30 min)**

El estudiante debe dibujar (en papel, Miro o draw.io) el diagrama de secuencia de una petición `GET /login`:

```
Browser → index.php → Application::boot() → [9 pasos] → Router → AuthController::loginForm() → Twig → Response
```

Deben identificar en qué paso exacto se detendría la ejecución si:
1. La base de datos está caída.
2. El archivo `.env` no existe.
3. La URI es `/.env` (archivo sensible).

**Parte C — Primera modificación (30 min)**

Añadir al bootstrap un paso que registre en `storage/logs/boot.log` la fecha/hora y la URI de cada petición, usando `file_put_contents()` en modo `FILE_APPEND`. El paso debe añadirse en `Application.php` como método privado `logRequest()` y llamarse desde `boot()` como el último paso.

**Criterio de aceptación:** el archivo `storage/logs/boot.log` crece con cada petición, sin romper el flujo normal.

### Punto de control del docente

Antes de cerrar la sesión, verificar que todos los equipos tienen:
- [ ] El servidor corriendo en `localhost:8000`
- [ ] Pueden iniciar sesión con el usuario admin creado por el seed
- [ ] Comprenden el orden del bootstrap y pueden explicar por qué `blockSensitiveUris()` es el primer paso

### Tarea para la siguiente sesión

Leer `app/routes/web.php` y `app/filters.php`. Responder por escrito:
1. ¿Cuál es la diferencia entre un filtro `before` y un filtro `after`?
2. ¿Qué devuelve un filtro para "cortar" la ejecución antes de llegar al controlador?
3. ¿Por qué la ruta `/logout` usa `POST` y no `GET`?

---

## SESIÓN 2 — Router, controladores y vistas

### Objetivos de aprendizaje

Al finalizar esta sesión el estudiante será capaz de:
- Registrar rutas RESTful con filtros de autenticación usando Phroute.
- Implementar un controlador con los métodos CRUD básicos.
- Crear plantillas Twig con herencia de layouts, variables globales y el helper `csrf_field()`.
- Aplicar el patrón PRG (Post-Redirect-Get) para evitar reenvíos de formulario.

### Conceptos clave a introducir

| Concepto | Archivo de referencia |
|---|---|
| Route groups y filtros | `app/routes/web.php` |
| Parámetros tipados en rutas `{id:i}` | `app/routes/web.php` líneas 66-70 |
| BaseController: `view()`, `json()`, `redirect()`, `abort()` | `app/Controllers/` cualquier controlador |
| Herencia de layouts en Twig (`extends`, `block`) | `app/Views/` |
| Flash messages y old input | `app/Helpers/` |

### Exploración guiada

```
app/routes/web.php        → mostrar grupos con Route::BEFORE
app/filters.php           → leer los 4 filtros en voz alta con la clase
app/Controllers/HomeController.php  → controlador más simple
app/Views/home/           → plantilla correspondiente
```

**Pregunta de facilitación:** "¿Qué pasa si un estudiante accede a `/admin/users` sin tener el rol admin? Traza la ejecución línea a línea hasta el redirect."

### Ejercicio central — Módulo de Perfil de Usuario

**Duración:** 90 minutos
**Modalidad:** Equipos de 2-3 personas

Implementar un módulo `/perfil` con las siguientes características:

**1. Ruta** (en `web.php`)
```php
// Ruta protegida — solo usuarios autenticados
$router->get('/perfil',  [PerfilController::class, 'show'],   [Route::BEFORE => 'auth']);
$router->post('/perfil', [PerfilController::class, 'update'], [Route::BEFORE => 'auth']);
```

**2. Controlador** (`app/Controllers/PerfilController.php`)

El método `show()` debe mostrar el nombre, email y rol del usuario actual.
El método `update()` debe permitir cambiar únicamente el nombre, con validación mínima (no vacío, máximo 80 caracteres). Usar el patrón PRG: al actualizar exitosamente, redirigir a `/perfil` con un flash de confirmación.

**3. Vista** (`app/Views/perfil/show.twig`)

Heredar del layout base. Mostrar un formulario con el campo nombre pre-llenado con `{{ old('nombre', auth_user.name) }}`. Mostrar mensajes flash si existen.

**4. Validación de la implementación**

- `GET /perfil` como usuario autenticado → muestra el formulario
- `POST /perfil` con nombre válido → redirige con mensaje de éxito
- `POST /perfil` con nombre vacío → muestra error en el mismo formulario
- `GET /perfil` sin autenticar → redirige a `/login`

### Punto de control del docente

Pedir a un equipo que explique frente al grupo:
- Cómo Phroute resuelve un `{id:i}` vs un `{token}` sin tipo.
- Por qué el formulario debe incluir `{{ csrf_field()|raw }}`.

### Tarea para la siguiente sesión

Leer `core/Database/DbContext.php` y `app/Models/User.php`. Identificar:
1. ¿Cómo se inicializa Eloquent sin el framework Laravel completo?
2. ¿Qué propiedades de `User` están en `$fillable` y por qué importa eso?
3. ¿Qué hace el accessor `getRoleNameAttribute()` si existe?

---

## SESIÓN 3 — Base de datos, modelos y RBAC

### Objetivos de aprendizaje

Al finalizar esta sesión el estudiante será capaz de:
- Configurar Eloquent ORM de forma standalone (sin Laravel).
- Escribir migraciones SQL y ejecutarlas con el runner del framework.
- Implementar relaciones Eloquent (`belongsTo`, `hasMany`, `belongsToMany`).
- Diseñar e implementar un sistema RBAC (roles y permisos) básico.
- Usar el trait `Auditable` para registrar cambios en modelos críticos.

### Conceptos clave a introducir

| Concepto | Archivo de referencia |
|---|---|
| Capsule Manager (Eloquent standalone) | `core/Database/DbContext.php` |
| Migraciones SQL numeradas | `database/migrations/` |
| Relaciones en modelos | `app/Models/User.php`, `app/Models/Role.php` |
| RBAC: roles y permisos | `database/migrations/001-004_*.sql` |
| Trait Auditable | `app/Models/` (BaseModel o AuditLog) |

### Exploración guiada

```
database/migrations/         → recorrer los 7 archivos en orden cronológico
database/migrate.php         → mostrar cómo funciona --fresh y --no-seed
app/Models/User.php          → relación belongsTo(Role::class)
app/Models/Role.php          → relación hasMany o belongsToMany a permisos
database/seeds/003_admin_user.php → cómo se crea el primer usuario
```

**Pregunta de facilitación:** "¿Por qué hay una tabla `szm_role_permissions` en lugar de guardar permisos directamente en `szm_users`? ¿Qué problema resuelve este diseño?"

### Ejercicio central — Módulo de Categorías con auditoría

**Duración:** 90 minutos
**Modalidad:** Equipos de 2-3 personas

**Parte A — Migración (20 min)**

Crear el archivo `database/migrations/008_szm_categories.sql`:

```sql
CREATE TABLE IF NOT EXISTS szm_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(100) NOT NULL UNIQUE,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NULL DEFAULT NULL,
    updated_at TIMESTAMP    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Ejecutar `php database/migrate.php` y verificar que la tabla existe.

**Parte B — Modelo (20 min)**

Crear `app/Models/Category.php` extendiendo `BaseModel`:
- `$table = 'szm_categories'`
- `$fillable = ['name', 'slug', 'active']`
- Scope local `active()` que filtra solo registros activos
- Mutator `setNameAttribute()` que genera automáticamente el `slug` con `strtolower(str_replace(' ', '-', $value))`

**Parte C — CRUD básico (50 min)**

Implementar `CategoryController` con métodos `index` (listado con paginación de 10), `store` (crear), `destroy` (eliminar). Rutas bajo `/admin/categories` con filtro `admin`.

**Verificación obligatoria:**
```bash
# Crear categoría vía curl
curl -X POST http://localhost:8000/admin/categories \
  -d "name=Tecnología&_token=<csrf_token>" \
  -b "SZM_SESSION=<session_id>"

# Verificar en BD
mysql -u root -p szm_core -e "SELECT * FROM szm_categories;"
```

### Punto de control del docente

Revisar con la clase:
- ¿Qué diferencia hay entre `save()` y `create()` en Eloquent?
- ¿Qué pasa si se intenta insertar un `slug` duplicado sin manejo de excepciones?

### Tarea para la siguiente sesión

Investigar qué es un ataque XSS, SQL injection y CSRF. Para cada uno: describir cómo funciona el ataque en 2 oraciones y qué contramedida específica implementa SZM-Core.

---

## SESIÓN 4 — Seguridad: CSRF, sesiones y WAF

> Esta es la sesión más densa del taller. Se recomienda que el docente tenga preparado el servidor con Postman o curl para demostraciones en vivo.

### Objetivos de aprendizaje

Al finalizar esta sesión el estudiante será capaz de:
- Explicar cómo funciona un ataque CSRF y cómo los tokens sincronizan el estado.
- Describir la arquitectura del WAF por capas (detección, identidad, comportamiento, HTTP).
- Identificar qué tipo de ataque detecta cada trait del WAF.
- Interpretar un log de ataque del WAF y determinar si es un falso positivo.
- Modificar la configuración del WAF a través de variables de entorno.

### Conceptos clave a introducir

| Concepto | Archivo de referencia |
|---|---|
| Tokens CSRF: generación y validación | `core/Security/CsrfToken.php` |
| Manejo seguro de sesiones | `core/Security/Session.php` |
| Arquitectura del WAF | `core/Security/Waf/Waf.php` |
| SecurityDetectionTrait: reglas de detección | `core/Security/Waf/Detection/SecurityDetectionTrait.php` |
| IpResolver: jerarquía de confianza de proxies | `core/Security/Waf/Identity/IpResolver.php` |
| Configuración vía `.env` | `WAF_TRUSTED_IPS`, `WAF_BYPASS_SECRET` |

### Exploración guiada

**Paso 1 — CSRF (10 min):** Abrir `core/Security/CsrfToken.php`. Mostrar que el token se genera una vez por sesión y se valida comparando con timing-safe `hash_equals()`. Señalar que `{{ csrf_field()|raw }}` en Twig es lo único que el desarrollador necesita recordar.

**Paso 2 — Flujo del WAF (20 min):** Abrir `core/Security/Waf/Waf.php`. Trazar en la pizarra el orden de ejecución:

```
blockForbiddenUris()       → URIs sensibles (.env, .git, .sql)
isWhitelisted()            → IPs en WAF_TRUSTED_IPS (vacío = nadie exento)
detectAiBot()              → herramientas de escaneo automático
checkBehavior()            → análisis de comportamiento (tasa de peticiones)
detectAttack()             → SQL injection, XSS, XXE, SSRF, path traversal...
evaluateScore()            → umbral de puntuación → bloqueo o baneo
```

**Pregunta de facilitación:** "¿Por qué `blockForbiddenUris()` debe ejecutarse ANTES de `isWhitelisted()`? ¿Qué vulnerabilidad existiría si el orden fuera al revés?"

### Ejercicio central — Laboratorio de ataques controlado

**Duración:** 90 minutos
**Modalidad:** Individual

> **Aviso importante para el docente:** Este laboratorio simula ataques contra el propio servidor local del estudiante (localhost). Está diseñado exclusivamente para entender cómo funciona la defensa. Aclarar explícitamente que estas técnicas NO deben usarse contra sistemas de terceros.

**Parte A — Verificar que el WAF detecta ataques (30 min)**

El estudiante debe ejecutar los siguientes payloads contra su servidor local usando curl o Postman, y para cada uno registrar: código HTTP recibido, qué regla del WAF lo detectó (revisar `szm_attack_logs_szm` en la BD), y cuál sería el impacto si el WAF no existiera.

```bash
# 1. SQL Injection básica
curl "http://localhost:8000/login?user=' OR '1'='1"

# 2. XSS reflejado
curl "http://localhost:8000/login?msg=<script>alert(1)</script>"

# 3. Path traversal
curl "http://localhost:8000/login?file=../../../../etc/passwd"

# 4. Acceso a archivo sensible
curl "http://localhost:8000/.env"
curl "http://localhost:8000/.git/config"
```

**Tabla a completar por el estudiante:**

| Payload | Código HTTP | Regla activada | Impacto sin WAF |
|---|---|---|---|
| SQL Injection | | | |
| XSS | | | |
| Path traversal | | | |
| Acceso .env | | | |

**Parte B — Configurar una IP de confianza (20 min)**

1. En `.env`, añadir `WAF_TRUSTED_IPS=127.0.0.1`
2. Reiniciar el servidor: `composer serve`
3. Repetir el ataque de SQL Injection del Paso A
4. Observar que la petición ya no se bloquea
5. Responder: ¿En qué escenario real tiene sentido usar `WAF_TRUSTED_IPS`? ¿Qué riesgo introduce si se configura mal?
6. Volver a dejarlo vacío: `WAF_TRUSTED_IPS=`

**Parte C — Interpretar el dashboard del WAF (40 min)**

Navegar a `http://localhost:8000/admin/waf` (iniciar sesión como admin primero).

El estudiante debe:
1. Identificar la IP que generó más ataques en la sesión.
2. Encontrar en `admin/waf/attack-logs` el ataque con mayor score.
3. Responder: ¿Qué significa que un ataque tenga score 30 vs score 80 en el sistema del WAF?
4. Usar `admin/waf/unban/{id}` para desbloquear la propia IP si fue bloqueada durante las pruebas.

### Punto de control del docente

Preguntas para verificar comprensión antes de cerrar:
1. ¿Cuál es la diferencia entre un ban temporal y un bloqueo por threshold score?
2. ¿Qué debería contener `WAF_BYPASS_SECRET` y quién debería conocer ese valor?
3. Si `WAF_TRUSTED_IPS` está vacío, ¿el WAF sigue funcionando? ¿Por qué?

### Tarea para la siguiente sesión

Revisar `phpunit.xml` y `tests/Waf/`. Intentar correr `composer test` y traer anotado cualquier error que aparezca, con la hipótesis de qué lo causa.

---

## SESIÓN 5 — Testing, auditoría y despliegue

### Objetivos de aprendizaje

Al finalizar esta sesión el estudiante será capaz de:
- Ejecutar la suite de tests del WAF e interpretar resultados de PHPUnit.
- Escribir un test unitario básico usando `#[DataProvider]` y `#[Test]`.
- Describir qué registra el audit log y para qué sirve en auditorías de seguridad.
- Preparar el framework para despliegue: variables de entorno de producción, permisos, caché.

### Conceptos clave a introducir

| Concepto | Archivo de referencia |
|---|---|
| PHPUnit 11: atributos `#[Test]`, `#[DataProvider]` | `tests/Waf/Detection/SqlInjectionTest.php` |
| WafDetectionProbe: proxy de test sin DB | `tests/Waf/WafDetectionProbe.php` |
| Audit log: trazabilidad de cambios | `app/Models/AuditLog.php`, tabla `szm_audit_log` |
| Modo producción: `APP_ENV=prod` | `config.php`, sección logging y caché Twig |

### Exploración guiada

```
tests/Waf/WafDetectionProbe.php     → por qué no necesita DB ni sesión
tests/Waf/Detection/SqlInjectionTest.php → leer 3 tests en voz alta
tests/Waf/Normalize/NormalizeTest.php    → qué es la normalización anti-bypass
phpunit.xml                         → mostrar las 4 suites y el directorio de coverage
```

**Pregunta de facilitación:** "¿Por qué usar `WafDetectionProbe` en lugar de instanciar `Waf` directamente en los tests? ¿Qué principio de diseño justifica esta separación?"

### Ejercicio central — Escribir tests y preparar para producción

**Duración:** 90 minutos
**Modalidad:** Equipos de 2-3 personas

**Parte A — Ejecutar y analizar la suite existente (20 min)**

```bash
composer test
```

Registrar cuántos tests pasan y si hay algún error. Si hay fallos, analizar el mensaje de PHPUnit e identificar si es un problema de entorno (extensión PHP faltante, variable no definida) o un bug real en el código.

**Parte B — Escribir un DataProvider propio (40 min)**

Crear `tests/Waf/Detection/PathTraversalExtendedTest.php` que pruebe 5 variantes adicionales de path traversal que NO estén cubiertas en los tests existentes. Usar `#[DataProvider]` con un método estático que devuelva los casos de prueba.

Estructura mínima requerida:
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
        // el test debe verificar que detectPathTraversal() retorna true
    }

    #[Test]
    #[DataProvider('safePathProvider')]
    public function no_genera_falso_positivo(string $payload): void
    {
        // el test debe verificar que detectPathTraversal() retorna false
    }

    public static function maliciousPathProvider(): array
    {
        return [
            // 5 variantes de path traversal que el equipo investiga
        ];
    }

    public static function safePathProvider(): array
    {
        return [
            // 3 rutas legítimas que NO deben detectarse como ataques
        ];
    }
}
```

**Parte C — Checklist de producción (30 min)**

El equipo debe completar el siguiente checklist y documentar el comando o cambio necesario para cada punto:

| # | Verificación | Comando / Cambio | Estado |
|---|---|---|---|
| 1 | `APP_ENV=prod` en `.env` | | |
| 2 | `SESSION_SECURE=true` en `.env` | | |
| 3 | `APP_URL` con dominio real configurado | | |
| 4 | `storage/logs/` con permisos 755 | `chmod -R 755 storage/` | |
| 5 | Caché de Twig se genera automáticamente en prod | Verificar que `storage/cache/twig/` se pobla | |
| 6 | `.env` no accesible vía HTTP | `curl http://localhost:8000/.env` → debe retornar 403 | |
| 7 | `composer.json` no accesible vía HTTP | `curl http://localhost:8000/composer.json` → 403 | |
| 8 | Directorio `/vendor` no accesible | `curl http://localhost:8000/vendor/autoload.php` → 403 | |
| 9 | `WAF_TRUSTED_IPS` vacío o con IPs específicas | | |
| 10 | Base de datos con usuario no-root para la app | Crear usuario MySQL con permisos mínimos | |

### Evaluación final del taller

Cada equipo presenta durante 10 minutos:
1. El módulo de Categorías implementado (Sesión 3) funcionando en el browser.
2. Los tests de `PathTraversalExtendedTest` corriendo con `composer test`.
3. El checklist de producción completado y firmado.

---

## Criterios de evaluación

### Rúbrica por sesión

| Criterio | 4 — Excelente | 3 — Satisfactorio | 2 — En desarrollo | 1 — Insuficiente |
|---|---|---|---|---|
| **Funcionalidad** | El código funciona sin errores y cubre todos los casos pedidos | Funciona con casos principales, falla en borde | Funciona parcialmente, errores en casos normales | No funciona o no se entregó |
| **Comprensión arquitectónica** | Puede explicar el por qué de cada decisión de diseño | Explica el qué pero no siempre el por qué | Describe el código pero no la arquitectura | No puede explicar el código entregado |
| **Seguridad** | No introduce vulnerabilidades, aplica defensas del framework correctamente | Aplica seguridad en flujo principal, olvida casos borde | Hay una vulnerabilidad identificable pero no crítica | Hay vulnerabilidades críticas (inyección, CSRF bypass, etc.) |
| **Testing** | Tests cubren casos positivos, negativos y bordes | Tests cubren casos positivos y negativos | Solo tests positivos (happy path) | No hay tests o todos fallan |
| **Trabajo en equipo** | Evidencia de commits distribuidos, todos explican el código | La mayoría del equipo puede explicar el código | Solo una persona puede explicar el código | No hay evidencia de colaboración |

### Ponderación sugerida

| Componente | Peso |
|---|---|
| Ejercicios de sesiones 1-4 | 40% |
| Módulo completo (Sesión 3 + 5) | 30% |
| Tests escritos (Sesión 5) | 15% |
| Checklist de producción (Sesión 5) | 10% |
| Participación en discusiones | 5% |

---

## Preguntas frecuentes del estudiante

**¿Por qué no usar Laravel directamente?**
Construir desde cero —con las mismas piezas que usa Laravel internamente (`illuminate/database`, `twig`)— obliga a entender qué hace cada capa. Laravel esconde esa complejidad. En el taller, el objetivo es que cada línea del framework sea explicable por el estudiante.

**¿Por qué PHP 8.3 y no una versión anterior?**
PHP 8.3 introduce `readonly` properties, `#[Attribute]`, `match`, tipos de unión y el operador nullsafe `?->`. El código usa estos features activamente. Trabajar con PHP 7.x requeriría reescribir partes significativas.

**¿El WAF reemplaza a un WAF de producción (Cloudflare, AWS WAF)?**
No. El WAF del framework es una capa de defensa en profundidad que opera a nivel de aplicación. Un WAF de perímetro (Cloudflare, F5) opera a nivel de red y es complementario, no sustituible.

**¿Puedo usar este framework en un proyecto real?**
Es un framework de aprendizaje. Para producción real, se recomienda Laravel, Symfony o Slim, que tienen comunidades activas y parches de seguridad regulares.

---

## Recursos complementarios para el docente

| Recurso | Propósito |
|---|---|
| [PHP-FIG PSR-4](https://www.php-fig.org/psr/psr-4/) | Entender el estándar de autoloading que usa Composer |
| [OWASP Top 10](https://owasp.org/Top10/) | Contexto de los ataques que detecta el WAF |
| [PHPUnit 11 Docs](https://docs.phpunit.de/en/11.0/) | Referencia de atributos y DataProviders |
| [Twig Documentation](https://twig.symfony.com/doc/3.x/) | Referencia de plantillas |
| [Eloquent Docs (Laravel)](https://laravel.com/docs/eloquent) | Referencia del ORM (aplica al uso standalone) |
| RFC 7235 (HTTP Auth) | Para profundizar en autenticación HTTP |

---

## Notas pedagógicas finales

**Sobre el ritmo:** Los ejercicios centrales de 90 minutos son ambiciosos. Si el grupo es heterogéneo, es preferible reducir el alcance del ejercicio que apresurarse. La comprensión profunda de 3 conceptos supera la implementación superficial de 10.

**Sobre los errores:** Cuando un equipo se bloquea, la primera pregunta del docente debe ser "¿qué dice el log?" antes de dar la respuesta. `storage/logs/` y los mensajes de excepción son las herramientas de diagnóstico por excelencia.

**Sobre la seguridad:** La Sesión 4 puede generar entusiasmo por "probar ataques". Reforzar explícitamente al inicio de esa sesión que el conocimiento de técnicas ofensivas es valioso solo cuando se usa para defender sistemas propios o bajo autorización escrita.

**Sobre Git:** Exigir que cada estudiante haga al menos un commit por parte de ejercicio. El historial de commits es evidencia de proceso, no solo de resultado final.