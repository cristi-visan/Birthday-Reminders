<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$route = (string) ($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Metadata and token endpoints are read by MCP clients, some running in a browser.
if (in_array($route, ['resource-metadata', 'server-metadata', 'register', 'token'], true)) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Protocol-Version');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

switch ($route) {
    case 'resource-metadata':
        json_out(OAuth::resourceMetadata());

    case 'server-metadata':
        json_out(OAuth::serverMetadata());

    case 'register':
        if ($method !== 'POST') {
            json_out(['error' => 'invalid_request'], 405, ['Allow' => 'POST']);
        }
        $req = read_json_body();
        if ($req === null) {
            json_out(['error' => 'invalid_client_metadata', 'error_description' => 'Body must be JSON'], 400);
        }
        [$status, $body] = OAuth::register($req);
        json_out($body, $status);

    case 'token':
        if ($method !== 'POST') {
            json_out(['error' => 'invalid_request'], 405, ['Allow' => 'POST']);
        }
        $req = $_POST;
        if (!$req && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $req = read_json_body() ?? [];
        }
        [$status, $body] = OAuth::token($req, request_header('Authorization'));
        json_out($body, $status, ['Pragma' => 'no-cache']);

    case 'authorize':
        handle_authorize($method);
        exit;

    default:
        json_out(['error' => 'not_found'], 404);
}

function handle_authorize(string $method): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self' https: http://localhost:* http://127.0.0.1:*");

    $p = $method === 'POST' ? $_POST : $_GET;
    $clientId = (string) ($p['client_id'] ?? '');
    $redirectUri = (string) ($p['redirect_uri'] ?? '');
    $state = (string) ($p['state'] ?? '');
    $client = $clientId !== '' ? OAuth::client($clientId) : null;

    // Without a known client + registered redirect URI we must not redirect anywhere.
    if (!$client) {
        render_page('Unknown app', '<p>This connection request came from an app that isn\'t registered. Try adding the connector again.</p>');
        return;
    }
    if ($redirectUri === '' && count($client['redirect_uris']) === 1) {
        $redirectUri = $client['redirect_uris'][0];
    }
    if (!in_array($redirectUri, $client['redirect_uris'], true)) {
        render_page('Invalid request', '<p>The redirect address doesn\'t match what this app registered.</p>');
        return;
    }

    $fail = static function (string $error, string $desc) use ($redirectUri, $state): void {
        redirect_back($redirectUri, ['error' => $error, 'error_description' => $desc, 'state' => $state]);
    };
    if (($p['response_type'] ?? '') !== 'code') {
        $fail('unsupported_response_type', 'Only response_type=code is supported');
        return;
    }
    $challenge = (string) ($p['code_challenge'] ?? '');
    if ($challenge === '' || ($p['code_challenge_method'] ?? '') !== 'S256') {
        $fail('invalid_request', 'PKCE with S256 is required');
        return;
    }
    $resource = (string) ($p['resource'] ?? OAuth::resourceUrl());
    if ($resource !== '' && strpos(rtrim($resource, '/'), base_url()) !== 0) {
        $fail('invalid_target', 'Unknown resource');
        return;
    }

    $error = '';
    if ($method === 'POST') {
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            $error = 'Your session expired — please try again.';
        } elseif (($_POST['decision'] ?? '') === 'deny') {
            $fail('access_denied', 'You declined the connection');
            return;
        } else {
            $ok = Auth::check();
            if (!$ok) {
                $attempt = Auth::attempt((string) ($_POST['password'] ?? ''));
                if ($attempt === null) {
                    $error = 'Too many attempts. Wait 15 minutes and try again.';
                } elseif ($attempt) {
                    Auth::login();
                    $ok = true;
                } else {
                    $error = 'Wrong password.';
                }
            }
            if ($ok) {
                $code = OAuth::issueCode($client, $redirectUri, $challenge, $resource);
                redirect_back($redirectUri, ['code' => $code, 'state' => $state, 'iss' => base_url()]);
                return;
            }
        }
    }

    $loggedIn = Auth::check();
    if (!Auth::passwordConfigured()) {
        render_page('Not set up yet', '<p>Set a password in <code>config.php</code> on the server first.</p>');
        return;
    }

    $hidden = '';
    foreach (['client_id', 'redirect_uri', 'state', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'scope'] as $k) {
        if (isset($p[$k])) {
            $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($p[$k]) . '">';
        }
    }
    $hidden .= '<input type="hidden" name="csrf" value="' . e(Auth::csrfToken()) . '">';
    $host = parse_url($redirectUri, PHP_URL_HOST);

    $body = '<p class="lead"><strong>' . e($client['client_name']) . '</strong> wants to read and edit your birthdays.</p>'
        . '<p class="muted">You\'ll be sent back to <code>' . e((string) $host) . '</code>.</p>'
        . ($error ? '<p class="error">' . e($error) . '</p>' : '')
        . '<form method="post">' . $hidden
        . ($loggedIn ? '' : '<label>Password<input type="password" name="password" autocomplete="current-password" autofocus required></label>')
        . '<div class="actions"><button type="submit" name="decision" value="deny" class="ghost" formnovalidate>Deny</button>'
        . '<button type="submit" name="decision" value="allow">Allow access</button></div></form>';
    render_page('Connect to Birthdays', $body);
}

function redirect_back(string $uri, array $params): void
{
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    $sep = strpos($uri, '?') === false ? '?' : '&';
    header('Location: ' . $uri . $sep . http_build_query($params), true, 302);
}

function render_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · Birthdays</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#f6f1e9;--card:#fffdf9;--ink:#211c17;--muted:#7a6f63;--line:#e7ddd0;--accent:#d9502b;--accent-ink:#fff;--err:#b3261e}
@media (prefers-color-scheme:dark){:root{--bg:#16130f;--card:#1f1b16;--ink:#f3ece2;--muted:#a3978a;--line:#342d25;--accent:#f0714a;--accent-ink:#1a120d;--err:#ff8a80}}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.5 Inter,system-ui,sans-serif;padding:16px}
.card{width:100%;max-width:400px;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:28px}
h1{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:26px;margin:0 0 12px}
.mark{font-size:30px;margin-bottom:8px}.lead{margin:0 0 6px}.muted{color:var(--muted);font-size:14px;margin:0 0 18px}
code{font-size:13px;background:var(--bg);padding:1px 6px;border-radius:6px}
label{display:block;font-size:14px;font-weight:500;margin-bottom:16px}
input[type=password]{display:block;width:100%;margin-top:6px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font:inherit}
.actions{display:flex;gap:10px;justify-content:flex-end}
button{font:inherit;font-weight:600;border:0;border-radius:12px;padding:11px 18px;cursor:pointer;background:var(--accent);color:var(--accent-ink)}
button.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
.error{color:var(--err);font-size:14px}
</style>
</head>
<body><main class="card"><div class="mark">🎂</div><h1>{$title}</h1>{$body}</main></body>
</html>
HTML;
}
