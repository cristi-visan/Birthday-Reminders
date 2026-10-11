<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string) ($_GET['route'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

// State-changing calls must come from our own JS (custom header can't be sent cross-site without CORS).
if ($method === 'POST' && request_header('X-Requested-With') !== 'fetch') {
    json_out(['error' => 'Bad request'], 400);
}

// ----- Public routes ----------------------------------------------------------------------
if ($route === 'session' && $method === 'GET') {
    json_out(['authenticated' => Auth::check(), 'password_configured' => Auth::passwordConfigured()]);
}
if ($route === 'login' && $method === 'POST') {
    $body = read_json_body() ?? [];
    if (!Auth::passwordConfigured()) {
        json_out(['error' => 'Set a password in config.php on the server first.'], 503);
    }
    $result = Auth::attempt((string) ($body['password'] ?? ''));
    if ($result === null) {
        json_out(['error' => 'Too many attempts. Try again in 15 minutes.'], 429);
    }
    if (!$result) {
        json_out(['error' => 'Wrong password.'], 401);
    }
    Auth::login();
    json_out(['ok' => true]);
}

if (!Auth::check()) {
    json_out(['error' => 'Not signed in'], 401);
}

$birthdays = new Birthdays(store());

try {
    switch (true) {
        case $route === 'logout' && $method === 'POST':
            Auth::logout();
            json_out(['ok' => true]);

        case $route === 'birthdays' && $method === 'GET':
            json_out(['today' => date('Y-m-d'), 'birthdays' => $birthdays->list($_GET['q'] ?? null, $_GET['tag'] ?? null)]);

        case $route === 'birthdays' && $method === 'POST':
            json_out(['birthday' => $birthdays->create(read_json_body() ?? [])], 201);

        case count($parts) === 2 && $parts[0] === 'birthdays' && $method === 'POST':
            $b = $birthdays->update($parts[1], read_json_body() ?? []);
            $b ? json_out(['birthday' => $b]) : json_out(['error' => 'Not found'], 404);

        case count($parts) === 3 && $parts[0] === 'birthdays' && $parts[2] === 'delete' && $method === 'POST':
            $birthdays->delete($parts[1]) ? json_out(['ok' => true]) : json_out(['error' => 'Not found'], 404);

        case $route === 'settings' && $method === 'GET':
            json_out([
                'mcp_url' => OAuth::resourceUrl(),
                'slack_configured' => Slack::configured(),
                'reminder_days' => array_values((array) cfg('reminder_days')),
                'timezone' => date_default_timezone_get(),
                'connections' => OAuth::connections(),
                'count' => $birthdays->count(),
                'version' => APP_VERSION,
            ]);

        case $route === 'slack/test' && $method === 'POST':
            $r = Slack::send("🎂 Test from Birthdays — Slack reminders are working.\n<" . base_url() . '|Open birthdays>');
            $r['ok'] ? json_out(['ok' => true]) : json_out(['error' => $r['error']], 502);

        case $route === 'reminders/preview' && $method === 'GET':
            $r = Reminders::run(true);
            json_out(['message' => $r['message'], 'count' => count($r['due'])]);

        case $route === 'connections/revoke' && $method === 'POST':
            json_out(['revoked' => OAuth::revokeAll()]);

        case $route === 'export' && $method === 'GET':
            header('Content-Disposition: attachment; filename="birthdays-' . date('Y-m-d') . '.json"');
            json_out($birthdays->export());
    }
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
}

json_out(['error' => 'Not found'], 404);
