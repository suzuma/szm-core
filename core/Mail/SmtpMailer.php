<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Contracts\MailerInterface;
use Core\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SmtpMailer — implementación SMTP sobre PHPMailer.
 *
 * Registro en providers.php (reemplaza NullMailer en producción):
 *
 *   ServicesContainer::bind(MailerInterface::class, fn() => new SmtpMailer(
 *       host:       $_ENV['MAIL_HOST'],
 *       port:       (int) $_ENV['MAIL_PORT'],
 *       username:   $_ENV['MAIL_USERNAME'],
 *       password:   $_ENV['MAIL_PASSWORD'],
 *       from:       $_ENV['MAIL_FROM_ADDRESS'],
 *       fromName:   $_ENV['MAIL_FROM_NAME'],
 *       encryption: 'tls',          // 'tls' | 'ssl' | ''
 *       debug:      false,
 *   ));
 *
 * Para envíos ricos (adjuntos, CC, BCC) usa sendMessage():
 *
 *   $mailer->sendMessage(
 *       Message::to($user->email, $user->name)
 *           ->subject('Reporte')
 *           ->html($html)
 *           ->attach('/tmp/report.pdf')
 *   );
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port       = 587,
        private readonly string $username   = '',
        private readonly string $password   = '',
        private readonly string $from       = '',
        private readonly string $fromName   = '',
        private readonly string $encryption = 'tls',  // 'tls' | 'ssl' | ''
        private readonly bool   $debug      = false,
    ) {}

    // ── MailerInterface ───────────────────────────────────────────────────

    /**
     * Envío simple: destinatario único, cuerpo HTML.
     * Implementa MailerInterface para compatibilidad con NullMailer.
     */
    public function send(string $to, string $subject, string $body, string $from = ''): bool
    {
        $message = Message::to($to)
            ->subject($subject)
            ->html($body)
            ->from($from !== '' ? $from : $this->from, $this->fromName);

        return $this->sendMessage($message);
    }

    // ── API rica ──────────────────────────────────────────────────────────

    /**
     * Envío completo: soporta CC, BCC, Reply-To, adjuntos.
     *
     * @throws \RuntimeException si PHPMailer lanza excepción
     */
    public function sendMessage(Message $message): bool
    {
        $mail = $this->buildMailer($message);

        try {
            $sent = $mail->send();
        } catch (\Exception $e) {
            Log::channel('mail')->error('SmtpMailer — error al enviar', [
                'to'      => $message->getTo(),
                'subject' => $message->getSubject(),
                'error'   => $e->getMessage(),
            ]);
            return false;
        }

        if (!$sent) {
            Log::channel('mail')->error('SmtpMailer — envío fallido', [
                'to'      => $message->getTo(),
                'subject' => $message->getSubject(),
                'error'   => $mail->ErrorInfo,
            ]);
        }

        return $sent;
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function buildMailer(Message $message): PHPMailer
    {
        $mail = new PHPMailer(exceptions: true);
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();

        // Servidor
        $mail->Host       = $this->host;
        $mail->Port       = $this->port;
        $mail->SMTPAuth   = ($this->username !== '');
        $mail->Username   = $this->username;
        $mail->Password   = $this->password;
        $mail->SMTPSecure = match ($this->encryption) {
            'tls'  => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };

        if ($this->debug) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }

        // Remitente
        $from     = $message->getFrom() !== '' ? $message->getFrom() : $this->from;
        $fromName = $message->getFromName() !== '' ? $message->getFromName() : $this->fromName;
        $mail->setFrom($from, $fromName);

        // Destinatarios
        $mail->addAddress($message->getTo(), $message->getToName());

        foreach ($message->getCc() as $cc) {
            $mail->addCC($cc);
        }
        foreach ($message->getBcc() as $bcc) {
            $mail->addBCC($bcc);
        }
        if ($message->getReplyTo() !== '') {
            $mail->addReplyTo($message->getReplyTo());
        }

        // Cuerpo
        $mail->isHTML(true);
        $mail->Subject = $message->getSubject();
        $mail->Body    = $message->getHtml();
        $mail->AltBody = $message->getText();

        // Adjuntos
        foreach ($message->getAttachments() as $att) {
            $mail->addAttachment($att['path'], $att['name']);
        }

        return $mail;
    }
}