<?php

declare(strict_types=1);

namespace Tests\Http\Requests;

use App\Http\Requests\LoginRequest;
use Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LoginRequestTest — valida las reglas de LoginRequest sin HTTP real.
 *
 * Técnica: se resetea el singleton de Request via ReflexionProperty
 * y se inyectan valores en $_POST antes de cada test, de modo que
 * LoginRequest::fromRequest() lea los datos de prueba.
 */
final class LoginRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetRequest();
        $_POST = [];
    }

    /** Resetea el singleton de Request para que el próximo capture() lo reconstruya. */
    private function resetRequest(): void
    {
        $prop = new \ReflectionProperty(Request::class, 'instance');
        $prop->setValue(null, null);
    }

    /**
     * Configura los superglobals y resetea el singleton antes de construir el FormRequest.
     */
    private function makeRequest(array $post): LoginRequest
    {
        $this->resetRequest();
        $_POST = $post;
        return LoginRequest::fromRequest();
    }

    // ── Casos válidos ─────────────────────────────────────────────────────

    #[Test]
    public function validCredentialsPasses(): void
    {
        $form = $this->makeRequest([
            'email'    => 'admin@example.com',
            'password' => 'secret123',
        ]);

        self::assertTrue($form->passes());
        self::assertFalse($form->fails());
        self::assertEmpty($form->fieldErrors());
    }

    // ── email: required ───────────────────────────────────────────────────

    #[Test]
    public function missingEmailFails(): void
    {
        $form = $this->makeRequest(['password' => 'secret123']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('email', $form->fieldErrors());
    }

    #[Test]
    public function emptyEmailFails(): void
    {
        $form = $this->makeRequest(['email' => '', 'password' => 'secret123']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('email', $form->fieldErrors());
    }

    // ── email: formato ────────────────────────────────────────────────────

    #[DataProvider('invalidEmails')]
    #[Test]
    public function invalidEmailFormatFails(string $email): void
    {
        $form = $this->makeRequest(['email' => $email, 'password' => 'secret123']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('email', $form->fieldErrors());
    }

    public static function invalidEmails(): array
    {
        return [
            'sin_arroba'   => ['notanemail'],
            'sin_dominio'  => ['user@'],
            'solo_arroba'  => ['@domain.com'],
            'doble_arroba' => ['u@@domain.com'],
        ];
    }

    // ── email: max 254 ────────────────────────────────────────────────────

    #[Test]
    public function emailOver254CharsFails(): void
    {
        $local  = str_repeat('a', 244);
        $email  = $local . '@test.com'; // 254+1 = 255 chars

        $form = $this->makeRequest(['email' => $email, 'password' => 'secret123']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('email', $form->fieldErrors());
    }

    // ── password: required ────────────────────────────────────────────────

    #[Test]
    public function missingPasswordFails(): void
    {
        $form = $this->makeRequest(['email' => 'user@test.com']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('password', $form->fieldErrors());
    }

    #[Test]
    public function emptyPasswordFails(): void
    {
        $form = $this->makeRequest(['email' => 'user@test.com', 'password' => '']);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('password', $form->fieldErrors());
    }

    // ── password: max 128 ────────────────────────────────────────────────

    #[Test]
    public function passwordOver128CharsFails(): void
    {
        $form = $this->makeRequest([
            'email'    => 'user@test.com',
            'password' => str_repeat('x', 129),
        ]);

        self::assertTrue($form->fails());
        self::assertArrayHasKey('password', $form->fieldErrors());
    }

    #[Test]
    public function passwordExactly128CharsIsValid(): void
    {
        $form = $this->makeRequest([
            'email'    => 'user@test.com',
            'password' => str_repeat('x', 128),
        ]);

        self::assertTrue($form->passes());
    }

    // ── Mensajes de error personalizados ──────────────────────────────────

    #[Test]
    public function missingEmailUsesCustomMessage(): void
    {
        $form   = $this->makeRequest(['password' => 'secret']);
        $errors = $form->fieldErrors();

        self::assertSame('El correo electrónico es requerido.', $errors['email']);
    }

    #[Test]
    public function invalidEmailUsesCustomMessage(): void
    {
        $form   = $this->makeRequest(['email' => 'bademail', 'password' => 'secret']);
        $errors = $form->fieldErrors();

        self::assertSame('Ingresa un correo electrónico válido.', $errors['email']);
    }

    // ── Múltiples errores simultáneos ─────────────────────────────────────

    #[Test]
    public function bothFieldsMissingReturnsBothErrors(): void
    {
        $form   = $this->makeRequest([]);
        $errors = $form->fieldErrors();

        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('password', $errors);
    }
}