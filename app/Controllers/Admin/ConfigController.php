<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Helpers\Flash;
use App\Models\AuditLog;
use Core\Config\EnvWriter;

/**
 * ConfigController — panel de configuración del sistema.
 *
 *   GET  /admin/config  → index()   muestra el formulario con tabs
 *   POST /admin/config  → update()  valida y persiste los cambios
 *
 * Acceso: solo rol admin (filtro Phroute en rutas).
 */
final class ConfigController extends BaseController
{
    /**
     * Variables agrupadas por tab.
     * El orden aquí determina el orden en la vista.
     *
     * @var array<string, list<array{key:string, label:string, type:string, validation:string}>>
     */
    private const TABS = [
        'general' => [
            ['key' => 'EMPRESA_NOMBRE',  'label' => 'Nombre del sistema',  'type' => 'text',   'validation' => 'max:100'],
            ['key' => 'APP_ENV',         'label' => 'Entorno',             'type' => 'select', 'validation' => 'enum:dev,prod,stop', 'options' => ['dev', 'prod', 'stop']],
            ['key' => 'APP_URL',         'label' => 'URL base',            'type' => 'text',   'validation' => 'url_or_empty'],
            ['key' => 'APP_TIMEZONE',    'label' => 'Zona horaria',        'type' => 'text',   'validation' => 'timezone'],
        ],
        'session' => [
            ['key' => 'SESSION_LIFETIME', 'label' => 'Duración (minutos)',  'type' => 'number', 'validation' => 'int:1:10080'],
            ['key' => 'SESSION_SECURE',   'label' => 'Solo HTTPS',          'type' => 'select', 'validation' => 'enum:true,false', 'options' => ['true', 'false']],
            ['key' => 'SESSION_SAMESITE', 'label' => 'Política SameSite',   'type' => 'select', 'validation' => 'enum:Lax,Strict,None', 'options' => ['Lax', 'Strict', 'None']],
        ],
        'mail' => [
            ['key' => 'MAIL_HOST',         'label' => 'Servidor SMTP',      'type' => 'text',     'validation' => 'max:253'],
            ['key' => 'MAIL_PORT',         'label' => 'Puerto',             'type' => 'number',   'validation' => 'int:1:65535'],
            ['key' => 'MAIL_USERNAME',     'label' => 'Usuario',            'type' => 'text',     'validation' => 'max:254'],
            ['key' => 'MAIL_PASSWORD',     'label' => 'Contraseña',         'type' => 'password', 'validation' => 'max:200'],
            ['key' => 'MAIL_FROM_ADDRESS', 'label' => 'Dirección remitente','type' => 'email',    'validation' => 'email'],
            ['key' => 'MAIL_FROM_NAME',    'label' => 'Nombre remitente',   'type' => 'text',     'validation' => 'max:100'],
        ],
        'waf' => [
            ['key' => 'WAF_TRUSTED_IPS',  'label' => 'IPs de confianza',  'type' => 'textarea', 'validation' => 'ips'],
            ['key' => 'WAF_BYPASS_SECRET','label' => 'Token de bypass',   'type' => 'password', 'validation' => 'hex_or_empty:64'],
            ['key' => 'REDIS_HOST',       'label' => 'Host Redis',         'type' => 'text',     'validation' => 'max:253'],
            ['key' => 'REDIS_PORT',       'label' => 'Puerto Redis',       'type' => 'number',   'validation' => 'int:1:65535'],
        ],
        'notifications' => [
            ['key' => 'TELEGRAM_BOT_TOKEN', 'label' => 'Token del bot', 'type' => 'password', 'validation' => 'max:200'],
            ['key' => 'TELEGRAM_CHAT_ID',   'label' => 'Chat ID',       'type' => 'text',     'validation' => 'numeric_or_empty'],
        ],
    ];

    /** Claves de solo lectura que se muestran como informativas en la vista. */
    private const READONLY_DISPLAY = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'];

    // ── GET /admin/config ─────────────────────────────────────────────────

    public function index(): string
    {
        $env    = $this->safeRead();
        $active = $this->request()->str('tab', 'general');
        if (!array_key_exists($active, self::TABS)) {
            $active = 'general';
        }

        // Extraer errores e input anterior guardados tras POST inválido
        $configErrors = $_SESSION['config_errors'] ?? [];
        $configInput  = $_SESSION['config_input']  ?? [];
        unset($_SESSION['config_errors'], $_SESSION['config_input']);

        return $this->view('admin/config/index.twig', [
            'tabs'          => self::TABS,
            'active_tab'    => $active,
            'env'           => $env,
            'readonly_keys' => self::READONLY_DISPLAY,
            'writable'      => is_writable($this->envPath()),
            'config_errors' => $configErrors,
            'config_input'  => $configInput,
        ]);
    }

    // ── POST /admin/config ────────────────────────────────────────────────

    public function update(): never
    {
        $req    = $this->request();
        $tab    = $req->str('tab', 'general');
        if (!array_key_exists($tab, self::TABS)) {
            $tab = 'general';
        }

        $env       = $this->safeRead();
        $errors    = [];
        $toWrite   = [];
        $oldValues = [];
        $newValues = [];

        // Solo validar los campos del tab activo — los demás no se envían en el formulario
        foreach (self::TABS[$tab] as $field) {
            $key  = $field['key'];
            $type = $field['type'];

            // Campos password vacíos → no se modifican
            if ($type === 'password' && $req->str($key) === '') {
                continue;
            }

            $raw = $req->str($key);

            $error = $this->validate($raw, $field);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }

            $currentValue = $env[$key] ?? '';
            if ($raw === $currentValue) {
                continue; // sin cambio
            }

            $toWrite[$key]   = $raw;
            $oldValues[$key] = EnvWriter::mask($key, $currentValue);
            $newValues[$key] = EnvWriter::mask($key, $raw);
        }

        if (!empty($errors)) {
            Flash::set('error', 'Hay errores en el formulario. Revisa los campos marcados.');
            $_SESSION['config_errors'] = $errors;
            $_SESSION['config_input']  = $req->all();
            $this->redirect("/admin/config?tab={$tab}");
        }

        if (empty($toWrite)) {
            Flash::set('info', 'No se detectaron cambios.');
            $this->redirect("/admin/config?tab={$tab}");
        }

        EnvWriter::writeMany($toWrite);

        AuditLog::record('config.updated', old: $oldValues, new: $newValues);

        Flash::set('success', 'Configuración guardada. Los cambios se aplican en la próxima petición.');
        $this->redirect("/admin/config?tab={$tab}");
    }

    // ── Validación ────────────────────────────────────────────────────────

    /**
     * Valida un valor según la regla de su campo.
     * Retorna null si es válido, o el mensaje de error si falla.
     */
    private function validate(string $value, array $field): ?string
    {
        $label      = $field['label'];
        $validation = $field['validation'];

        if (str_starts_with($validation, 'max:')) {
            $max = (int) substr($validation, 4);
            if (mb_strlen($value) > $max) {
                return "{$label}: máximo {$max} caracteres.";
            }
            return null;
        }

        if (str_starts_with($validation, 'int:')) {
            [, $min, $max] = explode(':', $validation);
            if ($value !== '' && (!ctype_digit($value) || (int)$value < (int)$min || (int)$value > (int)$max)) {
                return "{$label}: debe ser un número entre {$min} y {$max}.";
            }
            return null;
        }

        if (str_starts_with($validation, 'enum:')) {
            $options = explode(',', substr($validation, 5));
            if (!in_array($value, $options, true)) {
                return "{$label}: valor inválido.";
            }
            return null;
        }

        return match ($validation) {
            'email'           => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "{$label}: dirección de correo inválida.",
            'url_or_empty'    => ($value === '' || filter_var($value, FILTER_VALIDATE_URL)) ? null : "{$label}: URL inválida.",
            'timezone'        => in_array($value, \DateTimeZone::listIdentifiers(), true) ? null : "{$label}: zona horaria inválida.",
            'ips'             => $this->validateIpList($value) ? null : "{$label}: contiene IPs inválidas.",
            'hex_or_empty:64' => ($value === '' || (ctype_xdigit($value) && strlen($value) === 64)) ? null : "{$label}: debe ser hexadecimal de 64 caracteres o vacío.",
            'numeric_or_empty'=> ($value === '' || is_numeric($value)) ? null : "{$label}: debe ser numérico o vacío.",
            default           => null,
        };
    }

    private function validateIpList(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        foreach (explode(',', $value) as $ip) {
            if (filter_var(trim($ip), FILTER_VALIDATE_IP) === false) {
                return false;
            }
        }
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function safeRead(): array
    {
        try {
            return EnvWriter::read();
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function envPath(): string
    {
        return dirname(__DIR__, 3) . '/.env';
    }
}