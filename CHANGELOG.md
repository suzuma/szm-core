# Changelog

Todos los cambios notables de szm-core están documentados aquí.
Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.0.0/).

---

## [Unreleased]

### Pendiente
- Alerta de login desde IP nueva (`T8-a`)
- Trait `Auditable` en BaseModel (`T8-b`)
- WAF: detección de campaña coordinada (`WAF-a`)
- WAF: health score global (`WAF-b`)
- WAF: historial de baneos por IP (`WAF-c`)
- Tests unitarios de `AuthService` con base de datos (`S4-a` parcial)

---

## [Sprint 4] — 2026-04-24

### Añadido
- **Tests — `ResponseTest`** (`tests/Http/ResponseTest.php`): 14 tests que cubren todos los constructores nombrados (`html`, `json`, `redirect`, `make`), el builder inmutable (`withHeader`, `withStatus`, `withBody`) y los getters sin side-effects.
- **Tests — `RateLimiterTest`** (`tests/Security/RateLimiterTest.php`): 14 tests para el `RateLimiter` genérico usando `FakeCache`; cubre `hit()`, `tooManyAttempts()`, `remainingAttempts()`, `clear()`, aislamiento de claves y el factory `make()`.
- **Tests — `LoginRequestTest`** (`tests/Http/Requests/LoginRequestTest.php`): 16 tests de validación de `LoginRequest`; verifica `required`, `email`, `max` y mensajes de error personalizados sin necesidad de HTTP real.
- **Tests — `FakeCache`** (`tests/Support/FakeCache.php`): implementación in-memory de `CacheInterface` reutilizable por cualquier test.
- **Query Debug Panel** en `app/Views/layouts/app.twig`: panel flotante con todas las queries SQL ejecutadas en la petición (timing, bindings, contador); solo visible cuando `APP_ENV=dev`; colapsable vía JavaScript.
- **Global Twig `app_env`** en `core/TwigFactory.php`: disponible en todas las vistas para condicionar bloques de desarrollo.
- **Función Twig `debug_queries()`** en `core/TwigFactory.php`: devuelve `DbContext::getQueryLog()` en tiempo de render.
- `phpunit.xml`: añadidas suites `HTTP — Response y Requests` y `Security — RateLimiter`; ampliado bloque `<source>` con `core/Http`, `core/Security` y `app/Http`.

### Estado de tests
`291 tests, 368 assertions — OK`

---

## [Sprint 3] — 2026-04-24

### Añadido
- **`GET /health`** (`app/Controllers/HealthController.php`): endpoint sin autenticación que responde JSON con estado de DB (latencia), caché (driver + round-trip) y storage (driver + escritura); HTTP 200 si todo ok, 503 si algún componente falla.
- **`TelegramNotifier`** (`core/Notifications/TelegramNotifier.php`): envía mensajes a Telegram vía Bot API; soporta HTML (`<b>`, `<code>`, etc.); `sendWithRetry()` para alertas críticas; retorna `false` si `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` están vacíos.
- **`RateLimiter` genérico** (`core/Security/RateLimiter.php`): clase instantiable con `CacheInterface`; API: `hit()`, `tooManyAttempts()`, `remainingAttempts()`, `clear()`, `make()` factory estático.
- **Password history** (`database/migrations/010_szm_password_history.sql`, `app/Models/PasswordHistory.php`): tabla `szm_password_history` con FK en cascade; `PasswordHistory::matchesRecent()` + `record()`; `AuthService::resetPassword()` y `UserController::update()` rechazan las últimas 5 contraseñas.

### Corregido
- `database/migrations/009_waf_geo_coordinates.sql`: `ADD COLUMN` → `ADD COLUMN IF NOT EXISTS` para que el runner no falle si la migración ya fue aplicada.

---

## [Sprint 2] — 2026-04-23

### Añadido
- `public/css/app.css`: secciones de password strength, pagination, modal y dark mode tokens.
- `components/pagination.twig`: macro `render` con ellipsis automático.
- `components/modal.twig`: macros `dialog` (con caller) y `confirm` (simple).
- `layouts/app.twig` y `layouts/auth.twig`: dark mode toggle button + JS de tema.
- `public/css/auth.css`: dark mode overrides para el layout de autenticación.

### Corregido
- Dark mode toggle: `getElementById('htmlRoot')` → `document.documentElement` en ambos layouts.
- CSS vars de dark mode movidas de `[data-theme="dark"] body` a `[data-theme="dark"]` (selector canónico).

---

## [Sprint 1] — 2026-04-23

### Añadido
- **`SmtpMailer`** (`core/Mail/SmtpMailer.php`): wrappea PHPMailer v7, implementa `MailerInterface`; soporta `send()` y `sendMessage(Message $m)`.
- **`Message`** (`core/Mail/Message.php`): DTO inmutable con builder fluent (to, subject, html, cc, bcc, attach, replyTo).
- **Sistema de caché** (`core/Cache/`): `CacheInterface`, `ApcuCache`, `FileCache`, `NullCache`, fachada `Cache`.
- **Sistema de storage** (`core/Storage/`): `StorageInterface`, `LocalStorage`, fachada `Storage`.
- `providers.php`: auto-selección de driver (SmtpMailer si `MAIL_HOST` configurado, ApcuCache si APCu disponible, LocalStorage por defecto).
- **CLI generators**: `make:request`, `make:seeder`, `make:event` para `bin/szm`.

---

## [Pilar 4 — Seguridad] — 2026-04-22

### Añadido
- `LoginRateLimiter`: 10 intentos / 15 min por IP vía APCu; fallback silencioso si APCu no disponible.
- `AuditLog` modelo + tabla `szm_audit_log` + panel `/admin/audit-log`.
- `REMOTE_ADDR` sanitizado con `filter_var(FILTER_VALIDATE_IP)`.
- Contraseña truncada a 72 bytes antes de bcrypt en todos los puntos de hashing.
- bcrypt cost fijado a 12 en todos los puntos.

---

## [Pilar 3 — Arquitectura] — 2026-04-22

### Añadido
- `Core\Http\Response`: value object inmutable; named constructors `html`, `json`, `redirect`, `make`; builder `withHeader/Status/Body`; getters sin side-effects para tests; `send() → never`.
- `BaseController` refactorizado: helpers `redirect()`, `back()`, `json()`, `abort()`, `download()` usan `Response` internamente.
- `Application::dispatch()`: soporta retorno `Response` y `string`.
- `back()`: sanitiza Referer contra open redirect (solo acepta mismo host o URL relativa).
- `Core\Mail\NullMailer`: loguea en canal `mail` (storage/logs/mail-*.log) sin enviar.
- `bin/szm`: CLI con comandos `migrate`, `make:controller`, `make:model`, `make:migration`.
- Sistema de eventos: `EventDispatcher`, `UserLoggedIn`, `PasswordResetRequested`.

---

## [Pilar 2 — UX Flows] — 2026-04-22

### Añadido
- `OldInput::flash()`: preserva valores de formulario tras error.
- `Flash::set()`: mensajes de éxito/error entre redirects.
- Checkbox "Recordarme" en login (cookie 30 días).

---

## [Pilar 1 — UI/UX] — 2026-04-22

### Añadido
- `components/empty_state.twig`: estado vacío genérico.
- `components/stat_card.twig`: tarjeta KPI con colores.
- Breadcrumbs via `{% block breadcrumbs %}` en todas las vistas admin.
- Toast notifications (`window.toast()`) en `layouts/app.twig`.

---

## [WAF Dashboard] — sesiones anteriores

### Añadido
- KPI deltas comparativos (vs ayer / semana anterior).
- Endpoints más atacados (top URIs, 7 días).
- Columna User-Agent en log de ataques (40 chars truncados).
- Badges de severidad por regla (CRÍTICA / ALTA / MEDIA / BAJA).
- Tarjeta "Efectividad del WAF" (ban rate, IPs únicas).
- Exportación CSV con BOM en IPs bloqueadas y logs de ataques.
- Mapa de amenazas (`/admin/waf/geo-map`) con Leaflet + OpenStreetMap.
- Sync geo por IP vía AJAX (`POST /admin/waf/sync-geo/{id}`).
- "Ver ataques" por IP en tabla de IPs bloqueadas.
- Paginación 20 registros/página en IPs bloqueadas y logs.
- `database/migrations/009_waf_geo_coordinates.sql`: columnas `latitude`, `longitude` en `waf_blocked_ips_szm`.