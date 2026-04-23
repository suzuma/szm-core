<?php

declare(strict_types=1);

namespace Core\Mail;

/**
 * Message — DTO inmutable para construir un correo electrónico.
 *
 * Uso básico:
 *   $msg = Message::to('user@example.com', 'Juan Pérez')
 *       ->subject('Bienvenido')
 *       ->html('<h1>Hola!</h1>');
 *
 * Con adjuntos y destinatarios adicionales:
 *   $msg = Message::to($email)
 *       ->subject('Reporte mensual')
 *       ->html($body)
 *       ->cc('gerente@empresa.com')
 *       ->attach('/tmp/reporte.pdf', 'reporte-enero.pdf');
 */
final class Message
{
    private string  $to          = '';
    private string  $toName      = '';
    private string  $subject     = '';
    private string  $html        = '';
    private string  $text        = '';
    private string  $from        = '';
    private string  $fromName    = '';
    private string  $replyTo     = '';
    /** @var list<string> */
    private array   $cc          = [];
    /** @var list<string> */
    private array   $bcc         = [];
    /** @var list<array{path:string, name:string}> */
    private array   $attachments = [];

    private function __construct() {}

    // ── Named constructor ─────────────────────────────────────────────────

    public static function to(string $address, string $name = ''): self
    {
        $m       = new self();
        $m->to   = $address;
        $m->toName = $name;
        return $m;
    }

    // ── Builder (inmutable) ────────────────────────────────────────────────

    public function subject(string $subject): self
    {
        $clone          = clone $this;
        $clone->subject = $subject;
        return $clone;
    }

    public function html(string $html, string $text = ''): self
    {
        $clone       = clone $this;
        $clone->html = $html;
        $clone->text = $text !== '' ? $text : strip_tags($html);
        return $clone;
    }

    public function text(string $text): self
    {
        $clone       = clone $this;
        $clone->text = $text;
        return $clone;
    }

    public function from(string $address, string $name = ''): self
    {
        $clone           = clone $this;
        $clone->from     = $address;
        $clone->fromName = $name;
        return $clone;
    }

    public function replyTo(string $address): self
    {
        $clone          = clone $this;
        $clone->replyTo = $address;
        return $clone;
    }

    public function cc(string ...$addresses): self
    {
        $clone     = clone $this;
        $clone->cc = array_merge($clone->cc, $addresses);
        return $clone;
    }

    public function bcc(string ...$addresses): self
    {
        $clone      = clone $this;
        $clone->bcc = array_merge($clone->bcc, $addresses);
        return $clone;
    }

    /**
     * Adjunta un archivo al mensaje.
     *
     * @param string $path  Ruta absoluta al archivo
     * @param string $name  Nombre que verá el destinatario (opcional)
     */
    public function attach(string $path, string $name = ''): self
    {
        $clone                = clone $this;
        $clone->attachments[] = [
            'path' => $path,
            'name' => $name !== '' ? $name : basename($path),
        ];
        return $clone;
    }

    // ── Getters ───────────────────────────────────────────────────────────

    public function getTo(): string        { return $this->to; }
    public function getToName(): string    { return $this->toName; }
    public function getSubject(): string   { return $this->subject; }
    public function getHtml(): string      { return $this->html; }
    public function getText(): string      { return $this->text; }
    public function getFrom(): string      { return $this->from; }
    public function getFromName(): string  { return $this->fromName; }
    public function getReplyTo(): string   { return $this->replyTo; }
    /** @return list<string> */
    public function getCc(): array         { return $this->cc; }
    /** @return list<string> */
    public function getBcc(): array        { return $this->bcc; }
    /** @return list<array{path:string, name:string}> */
    public function getAttachments(): array { return $this->attachments; }
}