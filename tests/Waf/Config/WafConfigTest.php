<?php

declare(strict_types=1);

namespace Tests\Waf\Config;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Core\Security\Waf\WafConfig;

/**
 * WafConfigTest — verifica que todas las constantes de configuración
 * del WAF existen, tienen el tipo correcto y cumplen invariantes lógicos.
 *
 * Propósito: detectar regresiones si alguien modifica un valor
 * sin considerar el impacto en el resto del sistema.
 */
final class WafConfigTest extends TestCase
{
    // ── RATE LIMITING ─────────────────────────────────────────────────────────

    #[Test]
    public function rateLimitPerMinuteIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::RATE_LIMIT_PER_MINUTE);
    }

    #[Test]
    public function rateLimitCleanupProbabilityIsInValidRange(): void
    {
        self::assertGreaterThanOrEqual(1, WafConfig::RATE_LIMIT_CLEANUP_PROBABILITY);
        self::assertLessThanOrEqual(100, WafConfig::RATE_LIMIT_CLEANUP_PROBABILITY);
    }

    #[Test]
    public function rateLimitRecordTtlIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::RATE_LIMIT_RECORD_TTL_MINUTES);
    }

    // ── PUNTAJES DE RIESGO ────────────────────────────────────────────────────

    #[Test]
    public function banThresholdScoreIs20(): void
    {
        self::assertSame(20, WafConfig::BAN_THRESHOLD_SCORE);
    }

    #[Test]
    public function immediateBanScoreTriggersBanAlone(): void
    {
        // Un solo evento de baneo inmediato debe alcanzar el umbral por sí solo
        self::assertGreaterThanOrEqual(
            WafConfig::BAN_THRESHOLD_SCORE,
            WafConfig::IMMEDIATE_BAN_SCORE,
            'IMMEDIATE_BAN_SCORE debe ser >= BAN_THRESHOLD_SCORE para disparar el baneo en un solo evento'
        );
    }

    #[Test]
    public function normalBlockScoreIsLessThanThreshold(): void
    {
        // Un bloqueo normal no debe banear inmediatamente
        self::assertLessThan(
            WafConfig::BAN_THRESHOLD_SCORE,
            WafConfig::NORMAL_BLOCK_SCORE,
            'NORMAL_BLOCK_SCORE debe ser < BAN_THRESHOLD_SCORE'
        );
    }

    // ── DURACIONES ────────────────────────────────────────────────────────────

    #[Test]
    public function banDurationIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::BAN_DURATION_HOURS);
    }

    #[Test]
    public function attackLogRetentionIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::ATTACK_LOG_RETENTION_DAYS);
    }

    #[Test]
    public function requestLogRetentionIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::REQUEST_LOG_RETENTION_HOURS);
    }

    // ── DETECCIÓN DE COMPORTAMIENTO ───────────────────────────────────────────

    #[Test]
    public function nonHumanSpeedThresholdIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::NON_HUMAN_SPEED_THRESHOLD);
    }

    #[Test]
    public function nonHumanSpeedWindowIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::NON_HUMAN_SPEED_WINDOW_SECONDS);
    }

    #[Test]
    public function fuzzingAlertThresholdIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::FUZZING_ALERT_THRESHOLD);
    }

    // ── CACHE Y RENDIMIENTO ───────────────────────────────────────────────────

    #[Test]
    public function geoCacheTtlIs24Hours(): void
    {
        self::assertSame(86400, WafConfig::GEO_CACHE_TTL_SECONDS);
    }

    #[Test]
    public function normalizeMaxInputIsAtLeast1KB(): void
    {
        self::assertGreaterThanOrEqual(1024, WafConfig::NORMALIZE_MAX_INPUT_BYTES);
    }

    #[Test]
    public function geoApiTimeoutIsReasonable(): void
    {
        // Entre 1 y 10 segundos para no bloquear el ciclo de request
        self::assertGreaterThanOrEqual(1, WafConfig::GEO_API_TIMEOUT_SECONDS);
        self::assertLessThanOrEqual(10, WafConfig::GEO_API_TIMEOUT_SECONDS);
    }

    // ── DETECCIÓN DE IA ───────────────────────────────────────────────────────

    #[Test]
    public function aiPayloadMinLengthIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::AI_PAYLOAD_MIN_LENGTH);
    }

    #[Test]
    public function aiPromptInjectionRiskScoreIsPositive(): void
    {
        self::assertGreaterThan(0, WafConfig::AI_PROMPT_INJECTION_RISK_SCORE);
    }
}