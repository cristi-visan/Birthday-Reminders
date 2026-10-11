<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$token = bearer_token();
if ($token === null || !OAuth::validateAccessToken($token)) {
    $challenge = 'Bearer resource_metadata="' . base_url() . '/.well-known/oauth-protected-resource", scope="' . OAuth::SCOPE . '"';
    if ($token !== null) {
        $challenge .= ', error="invalid_token"';
    }
    json_out(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => $challenge]);
}

if ($method !== 'POST') {
    // No server-initiated stream; every response comes back on the POST.
    json_out(['error' => 'Method not allowed'], 405, ['Allow' => 'POST']);
}

$raw = (string) file_get_contents('php://input');
$payload = json_decode($raw, true);
if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
    json_out(Mcp::error(null, -32700, 'Parse error'), 400);
}

$server = new Mcp(new Birthdays(store()));

if (is_array($payload) && $payload !== [] && array_keys($payload) === range(0, count($payload) - 1)) {
    $responses = [];
    foreach ($payload as $msg) {
        $r = $server->handle($msg);
        if ($r !== null) {
            $responses[] = $r;
        }
    }
    if (!$responses) {
        http_response_code(202);
        exit;
    }
    json_out($responses);
}

$response = $server->handle($payload);
if ($response === null) {
    http_response_code(202);
    exit;
}
json_out($response);
