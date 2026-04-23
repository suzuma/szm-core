<?php

declare(strict_types=1);

use App\Events\PasswordResetRequested;
use App\Events\UserLoggedIn;
use App\Models\AuditLog;
use Core\Contracts\MailerInterface;
use Core\Events\EventDispatcher;
use Core\Mail\NullMailer;
use Core\ServicesContainer;

/**
 * Providers — registro de servicios y listeners de eventos del núcleo.
 *
 * ── Servicios (ServicesContainer) ─────────────────────────────────────────
 *
 * ServicesContainer::bind('id', fn() => new MiServicio());   // lazy singleton
 * ServicesContainer::instance('id', $objeto);                 // singleton manual
 *
 * ── Mailer ────────────────────────────────────────────────────────────────
 *
 * Por defecto se usa NullMailer (no envía — loguea en storage/logs/mail-*.log).
 * Para producción, reemplaza el binding con tu implementación SMTP, SendGrid, etc.:
 *
 *   ServicesContainer::bind(MailerInterface::class, fn() =>
 *       new \App\Mail\SmtpMailer(
 *           host:     $_ENV['MAIL_HOST'],
 *           port:     (int) $_ENV['MAIL_PORT'],
 *           username: $_ENV['MAIL_USERNAME'],
 *           password: $_ENV['MAIL_PASSWORD'],
 *           from:     $_ENV['MAIL_FROM_ADDRESS'],
 *           fromName: $_ENV['MAIL_FROM_NAME'],
 *       )
 *   );
 *
 * ── Eventos (EventDispatcher) ─────────────────────────────────────────────
 *
 * EventDispatcher::listen(MiEvento::class, function (MiEvento $e): void {
 *     // Lógica del listener
 * });
 */

// ── Mailer — NullMailer por defecto (sin SMTP) ────────────────────────────
ServicesContainer::bind(MailerInterface::class, fn() => new NullMailer());

// ── Audit log — eventos del núcleo de autenticación ───────────────────────

EventDispatcher::listen(UserLoggedIn::class, function (UserLoggedIn $e): void {
    AuditLog::record(
        action:  'user.login',
        entity:  $e->user,
        userId:  $e->user->id,
    );
});

EventDispatcher::listen(PasswordResetRequested::class, function (PasswordResetRequested $e): void {
    AuditLog::record(
        action:  'password.reset_request',
        entity:  $e->user,
        userId:  $e->user->id,
    );
});

// ── Email de recuperación de contraseña ───────────────────────────────────

EventDispatcher::listen(PasswordResetRequested::class, function (PasswordResetRequested $e): void {
    /** @var MailerInterface $mailer */
    $mailer  = ServicesContainer::get(MailerInterface::class);
    $appName = ServicesContainer::getConfig('app.name', 'SZM');
    $link    = (defined('_BASE_HTTP_') ? _BASE_HTTP_ : '') . '/reset-password/' . $e->token;

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head><meta charset="UTF-8"></head>
    <body style="font-family:system-ui,sans-serif;color:#1e293b;max-width:540px;margin:2rem auto;padding:0 1rem">
      <h2 style="color:#0f172a;margin-bottom:.5rem">Recupera tu contraseña</h2>
      <p>Hola <strong>{$e->user->name}</strong>,</p>
      <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta.
         Haz clic en el botón de abajo — el enlace es válido durante <strong>60 minutos</strong>.</p>
      <p style="text-align:center;margin:2rem 0">
        <a href="{$link}"
           style="background:#0f172a;color:#fff;padding:.75rem 1.75rem;border-radius:6px;
                  text-decoration:none;font-weight:600;display:inline-block">
          Restablecer contraseña
        </a>
      </p>
      <p style="font-size:.875rem;color:#64748b">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        <a href="{$link}" style="color:#0369a1;word-break:break-all">{$link}</a>
      </p>
      <p style="font-size:.875rem;color:#64748b">
        Si no solicitaste este cambio, puedes ignorar este correo — tu contraseña no se modificará.
      </p>
      <hr style="border:none;border-top:1px solid #e2e8f0;margin:1.5rem 0">
      <p style="font-size:.75rem;color:#94a3b8">{$appName}</p>
    </body>
    </html>
    HTML;

    $mailer->send(
        to:      $e->user->email,
        subject: "Recupera tu contraseña — {$appName}",
        body:    $html,
        from:    $_ENV['MAIL_FROM_ADDRESS'] ?? '',
    );
});