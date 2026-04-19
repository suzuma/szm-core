<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\OldInput;
use App\Http\Requests\ForgotRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetRequest;
use App\Services\AuthService;
use Core\Auth\Auth;
use Core\Security\CsrfToken;

/**
 * AuthController
 *
 * Orquesta input/output HTTP del ciclo de autenticación.
 * Validación de formularios → FormRequests.
 * Lógica de negocio → AuthService.
 *
 *   GET  /login                → loginForm()
 *   POST /login                → login()
 *   POST /logout               → logout()
 *   GET  /forgot-password      → forgotForm()
 *   POST /forgot-password      → forgot()
 *   GET  /reset-password/{tok} → resetForm()
 *   POST /reset-password       → reset()
 *   GET  /session-keepalive    → keepalive()
 */
final class AuthController extends BaseController
{
    private AuthService $auth;

    public function __construct()
    {
        parent::__construct();
        $this->auth = new AuthService();
    }

    // ── Login ──────────────────────────────────────────────────────────────

    public function loginForm(): string
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        return $this->view('auth/login.twig');
    }

    public function login(): never
    {
        $this->validateCsrf();

        $form = LoginRequest::fromRequest();

        if ($form->fails()) {
            OldInput::flash($form->only(['email']), $form->fieldErrors());
            Flash::set('error', $form->firstError());
            $this->back('/login');
        }

        try {
            $this->auth->attemptLogin($form->str('email'), $form->str('password'));
        } catch (\RuntimeException $e) {
            OldInput::flash($form->only(['email']));
            Flash::set('error', $e->getMessage());
            $this->back('/login');
        }

        if ($form->bool('remember_me')) {
            setcookie(session_name(), session_id(), time() + 30 * 86400, '/', '', false, true);
        }

        $this->redirect('/');
    }

    public function logout(): never
    {
        Auth::logout();
        $this->redirect('/login');
    }

    // ── Recuperación de contraseña ─────────────────────────────────────────

    public function forgotForm(): string
    {
        return $this->view('auth/forgot.twig');
    }

    public function forgot(): never
    {
        $this->validateCsrf();

        $form = ForgotRequest::fromRequest();

        if ($form->fails()) {
            OldInput::flash($form->only(['email']), $form->fieldErrors());
            Flash::set('error', $form->firstError());
            $this->back('/forgot-password');
        }

        // Silencioso — no revela si el email existe
        $this->auth->requestPasswordReset($form->str('email'));

        Flash::set('success', 'Si el correo está registrado recibirás las instrucciones en breve.');
        $this->redirect('/login');
    }

    public function resetForm(string $token): string
    {
        if ($this->auth->findUserByResetToken($token) === null) {
            Flash::set('error', 'El enlace de recuperación es inválido o ha expirado.');
            $this->redirect('/login');
        }

        return $this->view('auth/reset.twig', ['token' => $token]);
    }

    public function reset(): never
    {
        $this->validateCsrf();

        $form = ResetRequest::fromRequest();
        $token = $form->str('token');

        if ($form->fails()) {
            OldInput::flash([], $form->fieldErrors());
            Flash::set('error', $form->firstError());
            // Redirige solo si el token es válido como URL param
            $safe = preg_replace('/[^0-9a-f]/', '', $token);
            $this->redirect($safe ? '/reset-password/' . $safe : '/login');
        }

        if ($form->str('password') !== $form->str('confirm')) {
            OldInput::flash([], ['confirm' => 'Las contraseñas no coinciden.']);
            Flash::set('error', 'Las contraseñas no coinciden.');
            $this->redirect('/reset-password/' . $token);
        }

        try {
            $this->auth->resetPassword($token, $form->str('password'));
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            $this->redirect('/login');
        }

        Flash::set('success', 'Contraseña actualizada. Inicia sesión.');
        $this->redirect('/login');
    }

    // ── Session keepalive ──────────────────────────────────────────────────

    public function keepalive(): never
    {
        http_response_code(204);
        exit;
    }

    // ── Helpers privados ───────────────────────────────────────────────────

    private function validateCsrf(): void
    {
        $token = $_POST['_token'] ?? '';

        if (!CsrfToken::validate($token)) {
            $this->abort(419, 'Token CSRF inválido.');
        }
    }
}