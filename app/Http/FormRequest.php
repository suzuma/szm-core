<?php

declare(strict_types=1);

namespace App\Http;

use Core\Http\Request;

/**
 * FormRequest — validación declarativa de formularios.
 *
 * Cómo extender:
 *
 *   class LoginRequest extends FormRequest
 *   {
 *       public function rules(): array
 *       {
 *           return [
 *               'email'    => ['required', 'email'],
 *               'password' => ['required', 'min:8'],
 *           ];
 *       }
 *
 *       public function messages(): array
 *       {
 *           return [
 *               'email.required' => 'Ingresa tu correo.',
 *               'email.email'    => 'El formato del correo no es válido.',
 *           ];
 *       }
 *   }
 *
 *   // En el controlador:
 *   $form = LoginRequest::fromRequest();
 *   if ($form->fails()) {
 *       OldInput::flash($form->only(['email']), $form->fieldErrors());
 *       Flash::set('error', $form->firstError());
 *       $this->back('/login');
 *   }
 *   $email = $form->str('email');
 *
 * Reglas disponibles:
 *   required, email, min:N, max:N, confirmed, numeric, alpha, url
 */
abstract class FormRequest
{
    protected Request $request;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $passed = [];

    protected function __construct()
    {
        $this->request = Request::capture();
    }

    /**
     * Define las reglas de validación.
     * ['campo' => ['regla1', 'regla2:param']]
     */
    abstract public function rules(): array;

    /**
     * Mensajes de error personalizados.
     * ['campo.regla' => 'Mensaje.']
     */
    public function messages(): array
    {
        return [];
    }

    /** Ejecuta la validación y retorna la instancia. */
    public static function fromRequest(): static
    {
        $instance = new static();
        $instance->runValidation();
        return $instance;
    }

    // ── Resultado ─────────────────────────────────────────────────────────

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /** Primer error de cada campo: ['campo' => 'mensaje'] */
    public function fieldErrors(): array
    {
        $result = [];
        foreach ($this->errors as $field => $messages) {
            $result[$field] = $messages[0];
        }
        return $result;
    }

    /** Primer mensaje de error de cualquier campo. */
    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            return $messages[0];
        }
        return '';
    }

    /** Campos que pasaron todas las reglas, con sus valores. */
    public function validated(): array
    {
        return $this->passed;
    }

    // ── Acceso al request ─────────────────────────────────────────────────

    public function str(string $key, string $default = ''): string
    {
        return $this->request->str($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return $this->request->int($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return $this->request->bool($key, $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    public function only(array $keys): array
    {
        return $this->request->only($keys);
    }

    // ── Motor de validación ───────────────────────────────────────────────

    private function runValidation(): void
    {
        foreach ($this->rules() as $field => $rules) {
            $value  = $this->request->input($field);
            $strVal = trim((string) ($value ?? ''));

            $fieldFailed = false;
            foreach ($rules as $rule) {
                [$ruleName, $param] = $this->parseRule($rule);
                $error = $this->applyRule($field, $strVal, $ruleName, $param);

                if ($error !== null) {
                    $this->errors[$field][] = $error;
                    $fieldFailed = true;
                    break; // Un error por campo es suficiente
                }
            }

            if (!$fieldFailed) {
                $this->passed[$field] = $value;
            }
        }
    }

    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $param] = explode(':', $rule, 2);
            return [$name, $param];
        }
        return [$rule, null];
    }

    private function applyRule(string $field, string $strVal, string $rule, ?string $param): ?string
    {
        $custom = $this->messages()["{$field}.{$rule}"] ?? null;

        return match ($rule) {
            'required' => $strVal === ''
                ? ($custom ?? ucfirst($field) . ' es requerido.')
                : null,

            'email' => $strVal !== '' && !filter_var($strVal, FILTER_VALIDATE_EMAIL)
                ? ($custom ?? 'El formato del correo no es válido.')
                : null,

            'min' => $strVal !== '' && strlen($strVal) < (int) $param
                ? ($custom ?? "Mínimo {$param} caracteres.")
                : null,

            'max' => $strVal !== '' && strlen($strVal) > (int) $param
                ? ($custom ?? "Máximo {$param} caracteres.")
                : null,

            'confirmed' => $strVal !== $this->request->str($field . '_confirmation')
                ? ($custom ?? 'La confirmación no coincide.')
                : null,

            'numeric' => $strVal !== '' && !is_numeric($strVal)
                ? ($custom ?? 'El valor debe ser numérico.')
                : null,

            'alpha' => $strVal !== '' && !ctype_alpha($strVal)
                ? ($custom ?? 'Solo se permiten letras.')
                : null,

            'url' => $strVal !== '' && !filter_var($strVal, FILTER_VALIDATE_URL)
                ? ($custom ?? 'La URL no es válida.')
                : null,

            // Token hexadecimal de 64 chars (bin2hex de random_bytes(32))
            'hextoken' => $strVal !== '' && !preg_match('/^[0-9a-f]{64}$/', $strVal)
                ? ($custom ?? 'El token no tiene un formato válido.')
                : null,

            default => null,
        };
    }
}