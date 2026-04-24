<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Cache\CacheInterface;

/**
 * RateLimiter — límite de frecuencia genérico basado en CacheInterface.
 *
 * A diferencia de LoginRateLimiter (acoplado a APCu, con parámetros fijos),
 * esta clase es instanciable, testeable e independiente del driver de caché.
 *
 * Uso básico:
 *   $limiter = new RateLimiter($cache, key: 'upload:' . $userId, maxAttempts: 5, decaySeconds: 3600);
 *
 *   if ($limiter->tooManyAttempts()) {
 *       // HTTP 429 — demasiadas peticiones
 *   }
 *
 *   $limiter->hit();  // registrar el intento
 *
 *   // Tras operación exitosa (opcional, para resetear la ventana):
 *   $limiter->clear();
 *
 * Uso con fachada Cache:
 *   $limiter = RateLimiter::forCache(
 *       ServicesContainer::get(CacheInterface::class),
 *       'api:' . $ip,
 *       maxAttempts: 60,
 *       decaySeconds: 60,
 *   );
 *
 * La ventana de tiempo es fija (no deslizante): se inicia con el primer hit
 * y expira $decaySeconds después. Los hits posteriores dentro de la ventana
 * no renuevan el TTL.
 */
final class RateLimiter
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
    ) {}

    /**
     * Retorna true si el número de intentos actuales ≥ maxAttempts.
     * No modifica el contador — solo consulta.
     */
    public function tooManyAttempts(): bool
    {
        return $this->attempts() >= $this->maxAttempts;
    }

    /**
     * Registra un intento y retorna el nuevo total.
     *
     * Si es el primer hit de la ventana, crea el contador con el TTL.
     * Si ya existe, incrementa preservando el TTL original.
     */
    public function hit(): int
    {
        $result = $this->cache->increment($this->cacheKey(), 1, $this->decaySeconds);

        return $result === false ? 1 : $result;
    }

    /**
     * Retorna el número de intentos registrados en la ventana actual.
     * Si la ventana expiró o nunca se usó, retorna 0.
     */
    public function attempts(): int
    {
        return (int) $this->cache->get($this->cacheKey(), 0);
    }

    /**
     * Retorna cuántos intentos quedan antes de ser bloqueado.
     * Puede retornar 0 si ya se superó el límite.
     */
    public function remainingAttempts(): int
    {
        return max(0, $this->maxAttempts - $this->attempts());
    }

    /**
     * Elimina el contador — útil después de una operación exitosa
     * para devolver al usuario todos sus intentos disponibles.
     */
    public function clear(): void
    {
        $this->cache->delete($this->cacheKey());
    }

    /** Retorna el tamaño de la ventana de tiempo en segundos. */
    public function decaySeconds(): int
    {
        return $this->decaySeconds;
    }

    /** Retorna el máximo de intentos configurado. */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Factory estático — equivalente al constructor pero más legible en llamadas inline.
     *
     * Ejemplo:
     *   $limiter = RateLimiter::make($cache, 'export:' . $userId, maxAttempts: 3, decaySeconds: 60);
     */
    public static function make(
        CacheInterface $cache,
        string $key,
        int $maxAttempts,
        int $decaySeconds,
    ): self {
        return new self($cache, $key, $maxAttempts, $decaySeconds);
    }

    /** Clave interna hasheada para evitar colisiones y caracteres inválidos. */
    private function cacheKey(): string
    {
        return 'rl:' . hash('sha256', $this->key);
    }
}