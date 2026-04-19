<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * MailerInterface — contrato para el envío de correo electrónico.
 *
 * Cada proyecto implementa esta interfaz con el proveedor de su elección
 * (PHPMailer, Symfony Mailer, SendGrid, Resend, etc.) y la registra en
 * providers.php:
 *
 *   ServicesContainer::bind(MailerInterface::class, fn() => new SmtpMailer(
 *       host:     $_ENV['MAIL_HOST'],
 *       port:     (int) $_ENV['MAIL_PORT'],
 *       username: $_ENV['MAIL_USERNAME'],
 *       password: $_ENV['MAIL_PASSWORD'],
 *   ));
 *
 * Uso en servicios:
 *   /** @var MailerInterface $mailer *\/
 *   $mailer = ServicesContainer::get(MailerInterface::class);
 *   $mailer->send($user->email, 'Asunto', $htmlBody);
 */
interface MailerInterface
{
    /**
     * Envía un correo electrónico.
     *
     * @param string $to      Dirección del destinatario
     * @param string $subject Asunto del mensaje
     * @param string $body    Cuerpo en HTML del mensaje
     * @param string $from    Remitente; vacío usa el default configurado
     *
     * @return bool true si el mensaje fue aceptado por el servidor de correo
     */
    public function send(string $to, string $subject, string $body, string $from = ''): bool;
}