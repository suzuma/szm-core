<?php

declare(strict_types=1);

namespace Tests\Http;

use Core\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ResponseTest — verifica el value object Response.
 *
 * Todos los tests son pure unit: no tocan HTTP real ni llaman send().
 * Solo usan los getters que por diseño no tienen side-effects.
 */
final class ResponseTest extends TestCase
{
    // ── Constructores nombrados ────────────────────────────────────────────

    #[Test]
    public function htmlSetsBodyAndContentType(): void
    {
        $r = Response::html('<h1>OK</h1>');

        self::assertSame(200, $r->getStatus());
        self::assertSame('<h1>OK</h1>', $r->getBody());
        self::assertSame('text/html; charset=utf-8', $r->getHeader('Content-Type'));
    }

    #[Test]
    public function htmlAcceptsCustomStatus(): void
    {
        $r = Response::html('Not Found', 404);

        self::assertSame(404, $r->getStatus());
    }

    #[Test]
    public function jsonEncodesDataAndSetsContentType(): void
    {
        $r = Response::json(['ok' => true, 'items' => [1, 2, 3]]);

        self::assertSame(200, $r->getStatus());
        self::assertSame('application/json; charset=utf-8', $r->getHeader('Content-Type'));

        $decoded = json_decode($r->getBody(), true);
        self::assertTrue($decoded['ok']);
        self::assertSame([1, 2, 3], $decoded['items']);
    }

    #[Test]
    public function jsonAcceptsCustomStatus(): void
    {
        $r = Response::json(['error' => 'no encontrado'], 404);

        self::assertSame(404, $r->getStatus());
    }

    #[Test]
    public function jsonPreservesUnicodeAndSlashes(): void
    {
        $r = Response::json(['msg' => 'héroe/villano']);

        self::assertStringContainsString('héroe/villano', $r->getBody());
    }

    #[Test]
    public function redirectSetsLocationHeader(): void
    {
        $r = Response::redirect('/dashboard');

        self::assertSame(302, $r->getStatus());
        self::assertSame('/dashboard', $r->getHeader('Location'));
        self::assertTrue($r->isRedirect());
    }

    #[Test]
    public function redirectAcceptsPermanentStatus(): void
    {
        $r = Response::redirect('/nuevo', 301);

        self::assertSame(301, $r->getStatus());
    }

    #[Test]
    public function makeCreatesGenericResponse(): void
    {
        $r = Response::make('cuerpo', 204);

        self::assertSame(204, $r->getStatus());
        self::assertSame('cuerpo', $r->getBody());
        self::assertNull($r->getHeader('Content-Type'));
    }

    // ── Builder inmutable ─────────────────────────────────────────────────

    #[Test]
    public function withHeaderRetornsNewInstance(): void
    {
        $original = Response::make();
        $modified = $original->withHeader('X-Foo', 'bar');

        self::assertNotSame($original, $modified);
        self::assertNull($original->getHeader('X-Foo'));
        self::assertSame('bar', $modified->getHeader('X-Foo'));
    }

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $original = Response::make('', 200);
        $modified = $original->withStatus(422);

        self::assertNotSame($original, $modified);
        self::assertSame(200, $original->getStatus());
        self::assertSame(422, $modified->getStatus());
    }

    #[Test]
    public function withBodyReturnsNewInstance(): void
    {
        $original = Response::make('antes');
        $modified = $original->withBody('después');

        self::assertNotSame($original, $modified);
        self::assertSame('antes', $original->getBody());
        self::assertSame('después', $modified->getBody());
    }

    #[Test]
    public function builderChainIsImmutable(): void
    {
        $base = Response::make();
        $r    = $base
            ->withStatus(201)
            ->withHeader('X-Custom', 'value')
            ->withBody('body');

        // El objeto base no fue mutado
        self::assertSame(200, $base->getStatus());
        self::assertNull($base->getHeader('X-Custom'));
        self::assertSame('', $base->getBody());

        // El encadenado tiene todos los cambios
        self::assertSame(201, $r->getStatus());
        self::assertSame('value', $r->getHeader('X-Custom'));
        self::assertSame('body', $r->getBody());
    }

    // ── getHeaders() ──────────────────────────────────────────────────────

    #[Test]
    public function getHeadersReturnsAllHeaders(): void
    {
        $r = Response::make()
            ->withHeader('X-A', '1')
            ->withHeader('X-B', '2');

        $headers = $r->getHeaders();

        self::assertArrayHasKey('X-A', $headers);
        self::assertArrayHasKey('X-B', $headers);
        self::assertSame('1', $headers['X-A']);
    }

    // ── isRedirect() ──────────────────────────────────────────────────────

    #[Test]
    public function isRedirectFalseForNonRedirect(): void
    {
        self::assertFalse(Response::html('ok')->isRedirect());
        self::assertFalse(Response::json(['x' => 1])->isRedirect());
        self::assertFalse(Response::make()->isRedirect());
    }

    #[Test]
    public function isRedirectTrueWhenLocationPresent(): void
    {
        $r = Response::make()->withHeader('Location', '/ruta');

        self::assertTrue($r->isRedirect());
    }
}