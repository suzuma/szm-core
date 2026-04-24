<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Security\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCache;

/**
 * RateLimiterTest — verifica el comportamiento del rate limiter genérico.
 *
 * Usa FakeCache (in-memory) para eliminar cualquier dependencia de APCu
 * o sistema de archivos. Todos los tests son deterministas y sin efectos
 * secundarios fuera del proceso.
 */
final class RateLimiterTest extends TestCase
{
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FakeCache();
    }

    private function limiter(string $key = 'test:key', int $max = 3, int $decay = 60): RateLimiter
    {
        return new RateLimiter($this->cache, $key, $max, $decay);
    }

    // ── attempts() ────────────────────────────────────────────────────────

    #[Test]
    public function returnsZeroAttemptsInitially(): void
    {
        self::assertSame(0, $this->limiter()->attempts());
    }

    // ── hit() ─────────────────────────────────────────────────────────────

    #[Test]
    public function hitIncrementsCounter(): void
    {
        $limiter = $this->limiter();

        self::assertSame(1, $limiter->hit());
        self::assertSame(2, $limiter->hit());
        self::assertSame(3, $limiter->hit());
    }

    #[Test]
    public function attemptsReflectsHitCount(): void
    {
        $limiter = $this->limiter();
        $limiter->hit();
        $limiter->hit();

        self::assertSame(2, $limiter->attempts());
    }

    // ── tooManyAttempts() ─────────────────────────────────────────────────

    #[Test]
    public function notBlockedBelowLimit(): void
    {
        $limiter = $this->limiter(max: 3);
        $limiter->hit(); // 1
        $limiter->hit(); // 2

        self::assertFalse($limiter->tooManyAttempts());
    }

    #[Test]
    public function blockedAtExactLimit(): void
    {
        $limiter = $this->limiter(max: 3);
        $limiter->hit(); // 1
        $limiter->hit(); // 2
        $limiter->hit(); // 3 — alcanza el límite: bloqueado

        self::assertTrue($limiter->tooManyAttempts());
    }

    #[Test]
    public function blockedAboveLimit(): void
    {
        $limiter = $this->limiter(max: 3);
        $limiter->hit(); // 1
        $limiter->hit(); // 2
        $limiter->hit(); // 3
        $limiter->hit(); // 4 — supera el límite

        self::assertTrue($limiter->tooManyAttempts());
    }

    // ── remainingAttempts() ───────────────────────────────────────────────

    #[Test]
    public function remainingAttemptsDecreaseWithHits(): void
    {
        $limiter = $this->limiter(max: 5);

        self::assertSame(5, $limiter->remainingAttempts());
        $limiter->hit();
        self::assertSame(4, $limiter->remainingAttempts());
        $limiter->hit();
        self::assertSame(3, $limiter->remainingAttempts());
    }

    #[Test]
    public function remainingAttemptsIsZeroWhenExceeded(): void
    {
        $limiter = $this->limiter(max: 2);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit(); // supera

        self::assertSame(0, $limiter->remainingAttempts());
    }

    // ── clear() ───────────────────────────────────────────────────────────

    #[Test]
    public function clearResetsCounter(): void
    {
        $limiter = $this->limiter();
        $limiter->hit();
        $limiter->hit();
        $limiter->hit();

        $limiter->clear();

        self::assertSame(0, $limiter->attempts());
        self::assertFalse($limiter->tooManyAttempts());
    }

    #[Test]
    public function clearAllowsHitsAgain(): void
    {
        $limiter = $this->limiter(max: 2);
        $limiter->hit();
        $limiter->hit();
        $limiter->hit(); // bloqueado
        self::assertTrue($limiter->tooManyAttempts());

        $limiter->clear();
        $limiter->hit();

        self::assertFalse($limiter->tooManyAttempts());
    }

    // ── Claves distintas no interfieren ───────────────────────────────────

    #[Test]
    public function differentKeysAreIndependent(): void
    {
        $a = new RateLimiter($this->cache, 'user:1', 3, 60);
        $b = new RateLimiter($this->cache, 'user:2', 3, 60);

        $a->hit();
        $a->hit();
        $a->hit();
        $a->hit(); // user:1 bloqueado

        self::assertTrue($a->tooManyAttempts());
        self::assertFalse($b->tooManyAttempts()); // user:2 no afectado
    }

    // ── make() factory ────────────────────────────────────────────────────

    #[Test]
    public function makeFactoryProducesFunctionalLimiter(): void
    {
        $limiter = RateLimiter::make($this->cache, 'api:test', maxAttempts: 2, decaySeconds: 60);

        $limiter->hit();
        $limiter->hit();
        $limiter->hit();

        self::assertTrue($limiter->tooManyAttempts());
    }

    // ── Getters de configuración ──────────────────────────────────────────

    #[Test]
    public function decaySecondsReturnsConfiguredValue(): void
    {
        $limiter = $this->limiter(decay: 3600);
        self::assertSame(3600, $limiter->decaySeconds());
    }

    #[Test]
    public function maxAttemptsReturnsConfiguredValue(): void
    {
        $limiter = $this->limiter(max: 10);
        self::assertSame(10, $limiter->maxAttempts());
    }
}