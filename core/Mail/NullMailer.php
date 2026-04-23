<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Contracts\MailerInterface;
use Core\Log;

/**
 * NullMailer — mailer de desarrollo que NO envía correos.
 *
 * En lugar de enviar, registra el mensaje en el canal de log 'mail',
 * incluyendo el asunto y las primeras líneas del cuerpo (texto plano).
 * Esto permite al desarrollador ver el enlace de recuperación sin necesitar
 * un servidor SMTP configurado.
 *
 * En desarrollo: revisa storage/logs/mail-YYYY-MM-DD.log para ver el correo.
 *
 * Para usar un mailer real en producción, registra tu implementación en
 * providers.php ANTES del binding del NullMailer (o reemplázalo):
 *
 *   ServicesContainer::bind(MailerInterface::class, fn() =>
 *       new SmtpMailer(
 *           host:     $_ENV['MAIL_HOST'],
 *           port:     (int) $_ENV['MAIL_PORT'],
 *           username: $_ENV['MAIL_USERNAME'],
 *           password: $_ENV['MAIL_PASSWORD'],
 *           from:     $_ENV['MAIL_FROM_ADDRESS'],
 *           fromName: $_ENV['MAIL_FROM_NAME'],
 *       )
 *   );
 */
final class NullMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body, string $from = ''): bool
    {
        Log::channel('mail')->info('NullMailer — correo no enviado (configura un mailer real en providers.php)', [
            'to'      => $to,
            'from'    => $from ?: '(sin remitente)',
            'subject' => $subject,
            'preview' => substr(strip_tags($body), 0, 800),
        ]);

        return true;
    }
}