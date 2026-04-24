<?php

declare(strict_types=1);

namespace Core\Notifications;

/**
 * TelegramNotifier — envía mensajes vía Telegram Bot API.
 *
 * Requiere las variables de entorno:
 *   TELEGRAM_BOT_TOKEN  — token del bot (obtenido desde @BotFather)
 *   TELEGRAM_CHAT_ID    — ID del chat/grupo/canal de destino
 *
 * Si alguna variable está vacía, `send()` retorna false sin lanzar excepciones,
 * lo que permite desactivar notificaciones simplemente dejando las vars en blanco.
 *
 * Uso:
 *   TelegramNotifier::send("🚨 Login desde IP nueva: 192.168.1.1");
 *   TelegramNotifier::send("<b>WAF</b>: IP bloqueada por fuerza bruta.");
 *
 * El parámetro `$message` acepta HTML básico de Telegram:
 *   <b>negrita</b>, <i>cursiva</i>, <code>mono</code>, <pre>bloque</pre>
 */
final class TelegramNotifier
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const TIMEOUT  = 5;

    private function __construct() {}

    /**
     * Envía un mensaje al chat configurado en las variables de entorno.
     *
     * @param  string $message  Texto del mensaje (soporta HTML de Telegram).
     * @return bool             true si Telegram confirmó la recepción, false en cualquier otro caso.
     */
    public static function send(string $message): bool
    {
        $token  = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $chatId = $_ENV['TELEGRAM_CHAT_ID']   ?? '';

        if ($token === '' || $chatId === '') {
            return false;
        }

        $url     = self::API_BASE . $token . '/sendMessage';
        $payload = json_encode([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content'       => $payload,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $data = json_decode($result, true);

        return ($data['ok'] ?? false) === true;
    }

    /**
     * Envía un mensaje con reintentos (útil para alertas críticas).
     *
     * @param  string $message   Texto del mensaje.
     * @param  int    $attempts  Número máximo de intentos (default: 3).
     */
    public static function sendWithRetry(string $message, int $attempts = 3): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            if (self::send($message)) {
                return true;
            }
        }

        return false;
    }
}