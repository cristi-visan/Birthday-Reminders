<?php
declare(strict_types=1);

/**
 * Minimal OAuth 2.1 authorization server for the MCP endpoint:
 * dynamic client registration, authorization code + PKCE (S256), rotating refresh tokens.
 * Tokens are stored only as SHA-256 hashes.
 */
final class OAuth
{
    public const SCOPE = 'birthdays';
    private const CODE_TTL = 600;
    private const ACCESS_TTL = 86400;          // 1 day
    private const REFRESH_TTL = 86400 * 365;   // 1 year, rotated on every use
    private const MAX_CLIENTS = 100;

    public static function resourceUrl(): string
    {
        return base_url() . '/mcp';
    }

    public static function resourceMetadata(): array
    {
        return [
            'resource' => self::resourceUrl(),
            'authorization_servers' => [base_url()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Birthdays',
        ];
    }

    public static function serverMetadata(): array
    {
        $base = base_url();
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'scopes_supported' => [self::SCOPE],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    // ----- Dynamic client registration (RFC 7591) -----------------------------------------

    /** @return array{0:int,1:array} status + body */
    public static function register(array $req): array
    {
        $uris = $req['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris) {
            return [400, ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is required']];
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri)) {
                return [400, ['error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URIs must be https, or http on localhost']];
            }
        }
        $method = $req['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return [400, ['error' => 'invalid_client_metadata', 'error_description' => 'Unsupported token_endpoint_auth_method']];
        }
        $name = mb_substr(trim((string) ($req['client_name'] ?? 'MCP client')), 0, 80) ?: 'MCP client';

        $clientId = 'c_' . random_token(16);
        $secret = $method === 'none' ? null : random_token(32);
        $client = [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => $method,
            'secret_hash' => $secret ? token_hash($secret) : null,
            'created_at' => time(),
        ];

        $ok = store()->mutate('oauth', static function (array &$data) use ($client) {
            $data['clients'] = $data['clients'] ?? [];
            if (count($data['clients']) >= self::MAX_CLIENTS) {
                // Drop the oldest client that has no live tokens.
                $active = [];
                foreach (['access', 'refresh'] as $kind) {
                    foreach ($data[$kind] ?? [] as $t) {
                        $active[$t['client_id']] = true;
                    }
                }
                uasort($data['clients'], static fn ($a, $b) => $a['created_at'] <=> $b['created_at']);
                foreach ($data['clients'] as $id => $c) {
                    if (!isset($active[$id])) {
                        unset($data['clients'][$id]);
                        break;
                    }
                }
                if (count($data['clients']) >= self::MAX_CLIENTS) {
                    return false;
                }
            }
            $data['clients'][$client['client_id']] = $client;
            return true;
        });
        if (!$ok) {
            return [400, ['error' => 'invalid_client_metadata', 'error_description' => 'Too many registered clients']];
        }

        $body = [
            'client_id' => $clientId,
            'client_id_issued_at' => $client['created_at'],
            'client_name' => $name,
            'redirect_uris' => $client['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ];
        if ($secret) {
            $body['client_secret'] = $secret;
            $body['client_secret_expires_at'] = 0;
        }
        return [201, $body];
    }

    public static function validRedirectUri(string $uri): bool
    {
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) {
            return false;
        }
        if ($p['scheme'] === 'https') {
            return true;
        }
        return $p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    public static function client(string $clientId): ?array
    {
        $data = store()->read('oauth');
        return $data['clients'][$clientId] ?? null;
    }

    // ----- Authorization code -------------------------------------------------------------

    public static function issueCode(array $client, string $redirectUri, string $challenge, string $resource): string
    {
        $code = random_token(32);
        store()->mutate('oauth', static function (array &$data) use ($code, $client, $redirectUri, $challenge, $resource) {
            self::prune($data);
            $data['codes'][token_hash($code)] = [
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirectUri,
                'challenge' => $challenge,
                'resource' => $resource,
                'exp' => time() + self::CODE_TTL,
            ];
        });
        return $code;
    }

    /** Token endpoint. @return array{0:int,1:array} */
    public static function token(array $req, ?string $basicAuth): array
    {
        $grant = (string) ($req['grant_type'] ?? '');
        [$clientId, $clientSecret] = self::clientCredentials($req, $basicAuth);
        $client = $clientId !== '' ? self::client($clientId) : null;
        if (!$client) {
            return [401, ['error' => 'invalid_client', 'error_description' => 'Unknown client_id']];
        }
        if ($client['token_endpoint_auth_method'] !== 'none') {
            if ($clientSecret === '' || !hash_equals((string) $client['secret_hash'], token_hash($clientSecret))) {
                return [401, ['error' => 'invalid_client', 'error_description' => 'Bad client credentials']];
            }
        }

        if ($grant === 'authorization_code') {
            $code = (string) ($req['code'] ?? '');
            $verifier = (string) ($req['code_verifier'] ?? '');
            $redirectUri = (string) ($req['redirect_uri'] ?? '');
            $entry = store()->mutate('oauth', static function (array &$data) use ($code) {
                $h = token_hash($code);
                $entry = $data['codes'][$h] ?? null;
                unset($data['codes'][$h]); // single use
                return $entry;
            });
            if (!$entry || $entry['exp'] < time() || $entry['client_id'] !== $client['client_id']) {
                return [400, ['error' => 'invalid_grant', 'error_description' => 'Code is invalid or expired']];
            }
            if ($redirectUri !== '' && $redirectUri !== $entry['redirect_uri']) {
                return [400, ['error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch']];
            }
            if ($verifier === '' || !hash_equals($entry['challenge'], b64url(hash('sha256', $verifier, true)))) {
                return [400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']];
            }
            return [200, self::issueTokens($client['client_id'])];
        }

        if ($grant === 'refresh_token') {
            $refresh = (string) ($req['refresh_token'] ?? '');
            $entry = store()->mutate('oauth', static function (array &$data) use ($refresh) {
                $h = token_hash($refresh);
                $entry = $data['refresh'][$h] ?? null;
                unset($data['refresh'][$h]); // rotation
                return $entry;
            });
            if (!$entry || $entry['exp'] < time() || $entry['client_id'] !== $client['client_id']) {
                return [400, ['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired']];
            }
            return [200, self::issueTokens($client['client_id'])];
        }

        return [400, ['error' => 'unsupported_grant_type']];
    }

    private static function issueTokens(string $clientId): array
    {
        $access = random_token(32);
        $refresh = random_token(32);
        $now = time();
        store()->mutate('oauth', static function (array &$data) use ($access, $refresh, $clientId, $now) {
            self::prune($data);
            $data['access'][token_hash($access)] = ['client_id' => $clientId, 'exp' => $now + self::ACCESS_TTL];
            $data['refresh'][token_hash($refresh)] = ['client_id' => $clientId, 'exp' => $now + self::REFRESH_TTL];
            if (isset($data['clients'][$clientId])) {
                $data['clients'][$clientId]['last_used'] = $now;
            }
        });
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    private static function clientCredentials(array $req, ?string $basicAuth): array
    {
        if ($basicAuth && preg_match('/^\s*Basic\s+(\S+)/i', $basicAuth, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$id, $secret] = explode(':', $decoded, 2);
                return [urldecode($id), urldecode($secret)];
            }
        }
        return [(string) ($req['client_id'] ?? ''), (string) ($req['client_secret'] ?? '')];
    }

    // ----- Validation & housekeeping ------------------------------------------------------

    public static function validateAccessToken(string $token): bool
    {
        $static = (string) cfg('mcp_static_token', '');
        if ($static !== '' && strlen($static) >= 24 && hash_equals($static, $token)) {
            return true;
        }
        $data = store()->read('oauth');
        $entry = $data['access'][token_hash($token)] ?? null;
        return $entry !== null && $entry['exp'] >= time() && isset($data['clients'][$entry['client_id']]);
    }

    /** Lists connected clients (those holding a live refresh token). */
    public static function connections(): array
    {
        $data = store()->read('oauth');
        $live = [];
        foreach ($data['refresh'] ?? [] as $t) {
            if ($t['exp'] >= time()) {
                $live[$t['client_id']] = true;
            }
        }
        $out = [];
        foreach ($data['clients'] ?? [] as $id => $c) {
            if (isset($live[$id])) {
                $out[] = ['client_name' => $c['client_name'], 'last_used' => $c['last_used'] ?? null];
            }
        }
        return $out;
    }

    /** Disconnects every MCP client (they'll need to sign in again). */
    public static function revokeAll(): int
    {
        return store()->mutate('oauth', static function (array &$data) {
            $n = count($data['refresh'] ?? []);
            $data['codes'] = [];
            $data['access'] = [];
            $data['refresh'] = [];
            return $n;
        });
    }

    private static function prune(array &$data): void
    {
        $now = time();
        foreach (['codes', 'access', 'refresh'] as $kind) {
            $data[$kind] = $data[$kind] ?? [];
            foreach ($data[$kind] as $h => $entry) {
                if ($entry['exp'] < $now) {
                    unset($data[$kind][$h]);
                }
            }
        }
    }
}
