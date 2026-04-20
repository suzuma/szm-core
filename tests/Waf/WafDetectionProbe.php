<?php

declare(strict_types=1);

namespace Tests\Waf;

use Core\Security\Waf\Detection\SecurityDetectionTrait;

/**
 * WafDetectionProbe — Banco de pruebas para los detectores del WAF.
 *
 * Usa SecurityDetectionTrait directamente (sin Waf ni base de datos)
 * y expone sus métodos protegidos como públicos para tests unitarios.
 * Esto permite testear las reglas de detección en aislamiento total.
 *
 * No hereda de Waf para evitar la dependencia con Capsule/Redis/headers.
 */
final class WafDetectionProbe
{
    use SecurityDetectionTrait;

    // ── Reglas de detección ───────────────────────────────────────────────────

    public function detectSql(string $v): bool        { return $this->sql_injection($v); }
    public function detectXss(string $v): bool        { return $this->xss($v); }
    public function detectCmdInjection(string $v): bool { return $this->command_injection($v); }
    public function detectPathTraversal(string $v): bool { return $this->path_traversal($v); }
    public function detectXxe(string $v): bool        { return $this->xxe($v); }
    public function detectSsrf(string $v): bool       { return $this->ssrf($v); }
    public function detectOpenRedirect(string $v): bool { return $this->open_redirect($v); }

    // ── Normalización ─────────────────────────────────────────────────────────

    public function runNormalize(mixed $v): string    { return $this->normalize($v); }
}