<?php
declare(strict_types=1);

/** Posts a message to you on Slack: incoming webhook if set, otherwise a bot DM. */
final class Slack
{
    public static function configured(): bool
    {
        return cfg('slack_webhook_url') !== '' || (cfg('slack_bot_token') !== '' && cfg('slack_user_id') !== '');
    }

    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /** @return array{ok: bool, error: ?string} */
    public static function send(string $text): array
    {
        $webhook = (string) cfg('slack_webhook_url', '');
        if ($webhook !== '') {
            [$status, $body] = self::post($webhook, ['text' => $text], []);
            return $status === 200 ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => "Webhook HTTP $status: $body"];
        }
        $token = (string) cfg('slack_bot_token', '');
        $user = (string) cfg('slack_user_id', '');
        if ($token === '' || $user === '') {
            return ['ok' => false, 'error' => 'Slack is not configured (set slack_bot_token + slack_user_id, or slack_webhook_url, in config.php).'];
        }
        [$status, $body] = self::post('https://slack.com/api/chat.postMessage', [
            'channel' => $user,
            'text' => $text,
            'unfurl_links' => false,
        ], ['Authorization: Bearer ' . $token]);
        $json = json_decode($body, true);
        if ($status === 200 && ($json['ok'] ?? false)) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'Slack: ' . ($json['error'] ?? "HTTP $status")];
    }

    private static function post(string $url, array $payload, array $headers): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            return [$status, $response === false ? $error : (string) $response];
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, (string) $response];
    }
}
