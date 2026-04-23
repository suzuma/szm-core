<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Response — value object que representa una respuesta HTTP.
 *
 * Encapsula status, headers y body sin efectos secundarios en el constructor.
 * El envío real ocurre únicamente al llamar send() o sendHeaders().
 *
 * Uso típico desde BaseController (vía helpers):
 *   $this->redirect('/login');   // BaseController llama Response::redirect()->send()
 *   $this->json(['ok' => true]); // BaseController llama Response::json()->send()
 *
 * Uso directo en controladores o tests:
 *   return Response::html('<h1>OK</h1>');
 *   $r = Response::json(['x' => 1]); $r->getBody(); // sin side-effects
 *
 * Constructores nombrados:
 *   Response::html($body, $status)      → text/html
 *   Response::json($data, $status)      → application/json
 *   Response::redirect($url, $status)   → 302 Location
 *   Response::make($body, $status)      → sin Content-Type
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        private string $body   = '',
        private int    $status = 200,
    ) {}

    // ── Constructores nombrados ────────────────────────────────────────────

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Serializa $data como JSON y establece el Content-Type correspondiente.
     *
     * @throws \JsonException si $data no es serializable
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return (new self($body, $status))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Redirect a la URL indicada.
     *
     * @param int $status 301 (permanente) o 302 (temporal, por defecto)
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return (new self('', $status))
            ->withHeader('Location', $url);
    }

    /** Response genérica sin Content-Type — útil para respuestas 204 o custom. */
    public static function make(string $body = '', int $status = 200): self
    {
        return new self($body, $status);
    }

    // ── Builder (inmutable) ────────────────────────────────────────────────

    public function withHeader(string $name, string $value): self
    {
        $clone                  = clone $this;
        $clone->headers[$name]  = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone         = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone       = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // ── Getters (sin side-effects — ideales para tests) ───────────────────

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function isRedirect(): bool
    {
        return isset($this->headers['Location']);
    }

    // ── Envío HTTP ────────────────────────────────────────────────────────

    /**
     * Envía solo las cabeceras HTTP (sin body ni exit).
     * Usar para respuestas de streaming donde el body se escribe
     * directamente a php://output tras este método.
     */
    public function sendHeaders(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /**
     * Envía la respuesta completa (status + headers + body) y termina la ejecución.
     *
     * @return never
     */
    public function send(): never
    {
        $this->sendHeaders();
        echo $this->body;
        exit;
    }
}