<?php
// Copy this file to config.php on the server and fill it in.
// config.php is never committed and never overwritten by the deploy.

return [
    // Password for the web UI and for approving Claude's connection.
    // Plain text works; a bcrypt hash (starts with $2y$) is better.
    'password' => 'change-me',

    // Public address of the app, no trailing slash.
    'base_url' => 'https://birthdays.codefor.ge',

    'timezone' => 'Europe/Bucharest',

    // Where the JSON documents live. Safest outside the web root, e.g. '/home/CPANELUSER/birthdays-data'.
    // The default (inside the site folder) is blocked from the web by .htaccess.
    'data_dir' => __DIR__ . '/data',

    // Slack — either a bot token (xoxb-…, scope chat:write) plus your member ID (U…)…
    'slack_bot_token' => '',
    'slack_user_id' => '',
    // …or an incoming webhook URL (used instead of the bot when set).
    'slack_webhook_url' => '',

    // Days before a birthday to remind you. 0 = on the day.
    'reminder_days' => [7, 0],

    // Optional fixed bearer token for MCP clients without OAuth (e.g. Claude Code with a header).
    // At least 24 characters. Leave empty to allow OAuth only.
    'mcp_static_token' => '',
];
