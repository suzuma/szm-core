<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;

/**
 * UserLoggedIn — se despacha tras un login exitoso.
 *
 * Uso en listeners (providers.php):
 *   EventDispatcher::listen(UserLoggedIn::class, function (UserLoggedIn $e): void {
 *       Log::channel('audit')->info('login', [
 *           'user_id' => $e->user->id,
 *           'email'   => $e->user->email,
 *           'ip'      => $e->ip,
 *       ]);
 *   });
 */
final class UserLoggedIn
{
    public readonly string $ip;

    public function __construct(
        public readonly User $user,
    ) {
        $this->ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0]);
    }
}