<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbContext;
use Core\Security\CsrfToken;
use App\Helpers\Flash;
use App\Helpers\OldInput;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TwigFactory
{
    private function __construct() {}

    public static function create(string $environment, string $logPath): Environment
    {
        $viewsPath = _APP_PATH_ . 'Views';
        $cachePath = _CACHE_PATH_ . 'twig';

        $loader = new FilesystemLoader($viewsPath);

        $twig = new Environment($loader, [
            'debug'       => $environment === 'dev',
            'cache'       => $environment === 'prod' ? $cachePath : false,
            'auto_reload' => true,
        ]);

        // Campo oculto con token CSRF listo para pegar en formularios
        $twig->addFunction(new TwigFunction('csrf_field', function (): string {
            $token = CsrfToken::get();
            return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        }, ['is_safe' => ['html']]));

        // Solo el valor del token (para headers AJAX)
        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            return CsrfToken::get();
        }));

        // Lee y elimina un mensaje flash
        $twig->addFunction(new TwigFunction('flash', function (string $type): string {
            return Flash::get($type);
        }));

        // Verifica si existe un mensaje flash (sin consumirlo)
        $twig->addFunction(new TwigFunction('has_flash', function (string $type): bool {
            return Flash::has($type);
        }));

        // URL de un asset (CSS, JS, imágenes)
        $twig->addFunction(new TwigFunction('asset', function (string $path): string {
            return rtrim(_BASE_HTTP_, '/') . '/' . ltrim($path, '/');
        }));

        // URL de una ruta
        $twig->addFunction(new TwigFunction('url', function (string $path): string {
            return rtrim(_BASE_HTTP_, '/') . '/' . ltrim($path, '/');
        }));

        // Queries SQL ejecutadas en la petición actual (solo disponible en dev)
        $twig->addFunction(new TwigFunction('debug_queries', function (): array {
            return DbContext::getQueryLog();
        }));

        // Variables globales disponibles en todas las plantillas
        $twig->addGlobal('app_env',        $environment);
        $twig->addGlobal('app_name',       _EMPRESA_);
        $twig->addGlobal('base_url',       _BASE_HTTP_);
        $twig->addGlobal('auth_role',      $_SESSION['user_role'] ?? 'guest');
        $twig->addGlobal('auth_user_name', $_SESSION['user']['name'] ?? '');
        // URI actual — permite marcar nav activo sin lógica en controladores
        $twig->addGlobal('_uri', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        // Old input values + errores por campo (se consumen en el primer render)
        [$oldInput, $fieldErrors] = OldInput::pull();
        $twig->addGlobal('old',    $oldInput);
        $twig->addGlobal('errors', $fieldErrors);

        // Lifetime de sesión en minutos (para modal de timeout en frontend)
        $twig->addGlobal('session_lifetime', (int) ini_get('session.gc_maxlifetime') / 60);

        // Nonce CSP generado por el WAF en cada request.
        // Úsalo en cada <script> inline o externo: <script nonce="{{ csp_nonce }}">
        // Sin el nonce, el navegador bloquea el script cuando la CSP está activa.
        $twig->addGlobal('csp_nonce', $_SERVER['CSP_NONCE'] ?? '');

        return $twig;
    }
}