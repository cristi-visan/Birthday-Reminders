<?php
declare(strict_types=1);

/*
 * Daily birthday reminder — run from a cPanel cron job, e.g. every day at 09:00:
 *   /usr/local/bin/php /home/USER/birthdays.codefor.ge/cron/remind.php
 *
 * Options:
 *   --dry-run   show what would be sent, send nothing
 *   --test      send a test message to Slack
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);

if (in_array('--test', $args, true)) {
    $r = Slack::send('🎂 Test from the Birthdays cron job — reminders will arrive here.');
    echo $r['ok'] ? "Sent.\n" : 'Failed: ' . $r['error'] . "\n";
    exit($r['ok'] ? 0 : 1);
}

$dry = in_array('--dry-run', $args, true);
$result = Reminders::run($dry);

if (!$result['due']) {
    echo date('Y-m-d H:i') . " — nothing to remind today.\n";
    exit(0);
}
echo $result['message'] . "\n\n";
if ($dry) {
    echo "(dry run — nothing sent)\n";
    exit(0);
}
if ($result['sent']) {
    echo 'Sent ' . count($result['due']) . " reminder(s).\n";
    exit(0);
}
fwrite(STDERR, 'Sending failed: ' . $result['error'] . "\n");
exit(1);
