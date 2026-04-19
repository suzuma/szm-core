<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;

/**
 * PasswordResetRequested — se despacha cuando se genera un token de recuperación.
 *
 * El proyecto implementa el listener que envía el email:
 *
 *   EventDispatcher::listen(PasswordResetRequested::class,
 *       function (PasswordResetRequested $e): void {
 *           /** @var MailerInterface $mailer *\/
 *           $mailer = ServicesContainer::get(MailerInterface::class);
 *           $link   = _BASE_HTTP_ . '/reset-password/' . $e->token;
 *           $mailer->send(
 *               $e->user->email,
 *               'Recupera tu contraseña',
 *               "<p>Haz clic aquí para restablecer tu contraseña:</p><a href=\"{$link}\">{$link}</a>",
 *           );
 *       }
 *   );
 */
final class PasswordResetRequested
{
    public function __construct(
        public readonly User   $user,
        public readonly string $token,  // token en texto plano — para construir el enlace
    ) {}
}