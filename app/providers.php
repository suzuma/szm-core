<?php

declare(strict_types=1);

use App\Events\PasswordResetRequested;
use App\Events\UserLoggedIn;
use App\Models\AuditLog;
use Core\Events\EventDispatcher;
use Core\ServicesContainer;

/**
 * Providers — registro de servicios y listeners de eventos del núcleo.
 *
 * ── Servicios (ServicesContainer) ─────────────────────────────────────────
 *
 * ServicesContainer::bind('id', fn() => new MiServicio());   // lazy singleton
 * ServicesContainer::instance('id', $objeto);                 // singleton manual
 *
 * Ejemplo — mailer con SMTP:
 *
 *   ServicesContainer::bind(\Core\Contracts\MailerInterface::class, fn() =>
 *       new \App\Mail\SmtpMailer(
 *           host: $_ENV['MAIL_HOST'],
 *           port: (int) $_ENV['MAIL_PORT'],
 *           user: $_ENV['MAIL_USER'],
 *           pass: $_ENV['MAIL_PASS'],
 *       )
 *   );
 *
 * ── Eventos (EventDispatcher) ─────────────────────────────────────────────
 *
 * EventDispatcher::listen(MiEvento::class, function (MiEvento $e): void {
 *     // Lógica del listener
 * });
 */

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