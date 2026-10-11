<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

$configFile = getenv('BIRTHDAYS_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    $msg = 'Setup needed: copy config.example.php to config.php and fill it in.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$GLOBALS['APP_CONFIG'] = array_merge([
    'password' => '',
    'base_url' => '',
    'timezone' => 'Europe/Bucharest',
    'data_dir' => APP_ROOT . '/data',
    'slack_bot_token' => '',
    'slack_user_id' => '',
    'slack_webhook_url' => '',
    'reminder_days' => [7, 0],
    'mcp_static_token' => '',
], (array) require $configFile);

date_default_timezone_set((string) $GLOBALS['APP_CONFIG']['timezone']);

require __DIR__ . '/helpers.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Birthdays.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/OAuth.php';
require __DIR__ . '/Slack.php';
require __DIR__ . '/Reminders.php';
require __DIR__ . '/Mcp.php';
