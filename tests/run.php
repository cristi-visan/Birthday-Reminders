<?php
declare(strict_types=1);

// Smoke tests: php tests/run.php  (runs in CI before every deploy)

$tmp = sys_get_temp_dir() . '/birthdays-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'password' => 'secret-test',
    'base_url' => 'https://birthdays.test',
    'timezone' => 'Europe/Bucharest',
    'data_dir' => $tmp . '/data',
    'reminder_days' => [7, 0],
], true) . ';');
putenv('BIRTHDAYS_CONFIG=' . $tmp . '/config.php');

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

$b = new Birthdays(store());
$today = new DateTimeImmutable('2026-10-11');

echo "Birthdays\n";
$ana = $b->create(['name' => 'Ana Popescu', 'day' => 18, 'month' => 10, 'year' => 1992, 'tags' => 'family, Family, sister']);
check('create assigns id', strlen($ana['id']) === 12);
check('tags trimmed and de-duplicated', $ana['tags'] === ['family', 'sister']);
$e = Birthdays::enrich($ana, $today);
check('days_until 7', $e['days_until'] === 7);
check('turns 34', $e['turns'] === 34 && $e['age'] === 33);

$leap = Birthdays::enrich(['day' => 29, 'month' => 2, 'year' => 2000] + $ana, $today);
check('29 Feb falls on 28 Feb in 2027', $leap['next_date'] === '2027-02-28');

$past = Birthdays::enrich(['day' => 1, 'month' => 1, 'year' => null] + $ana, $today);
check('past date rolls to next year', $past['next_date'] === '2027-01-01' && $past['turns'] === null);

$todayB = Birthdays::enrich(['day' => 11, 'month' => 10, 'year' => 1980] + $ana, $today);
check('today: days_until 0, age == turns', $todayB['days_until'] === 0 && $todayB['age'] === 46);

check('rejects 31 April', throws(fn () => $b->create(['name' => 'X', 'day' => 31, 'month' => 4])));
check('rejects 29 Feb 2001', throws(fn () => $b->create(['name' => 'X', 'day' => 29, 'month' => 2, 'year' => 2001])));
check('accepts 29 Feb without year', (bool) $b->create(['name' => 'Leap Kid', 'day' => 29, 'month' => 2]));
check('rejects empty name', throws(fn () => $b->create(['name' => ' ', 'day' => 1, 'month' => 1])));
check('rejects future year', throws(fn () => $b->create(['name' => 'X', 'day' => 1, 'month' => 1, 'year' => 2999])));

$upd = $b->update($ana['id'], ['year' => null, 'notes' => 'Likes tea']);
check('partial update keeps name, clears year', $upd['name'] === 'Ana Popescu' && $upd['year'] === null && $upd['notes'] === 'Likes tea');
check('update unknown id returns null', $b->update('nope', ['notes' => 'x']) === null);
check('update rejects invalid merged date', throws(fn () => $b->update($ana['id'], ['day' => 31, 'month' => 11])));

$b->create(['name' => 'Mihai Ionescu', 'day' => 11, 'month' => 10, 'year' => 1986, 'gift_ideas' => 'Vinyl']);
check('search by note', count($b->list('tea', null, null, $today)) === 1);
check('filter by tag', count($b->list(null, 'SISTER', null, $today)) === 1);
check('upcoming 7 days = Mihai + Ana', array_column($b->upcoming(7, $today), 'name') === ['Mihai Ionescu', 'Ana Popescu']);
check('similar names', count($b->similar('ana')) === 1);

echo "Reminders\n";
$run = Reminders::run(true, $today);
check('dry run finds 2 due', count($run['due']) === 2);
check('message mentions today + 7 days', strpos((string) $run['message'], '*Today*') !== false && strpos((string) $run['message'], '*In 7 days*') !== false);
$run = Reminders::run(false, $today);
check('send fails cleanly without Slack', $run['sent'] === false && $run['error'] !== null);
check('nothing marked sent after failure', store()->read('reminders_sent') === []);
store()->mutate('reminders_sent', function (array &$d) use ($ana) {
    $d[$ana['id'] . '|2026-10-18|7'] = '2026-10-11';
});
$run = Reminders::run(true, new DateTimeImmutable('2026-10-14'));
check('already-sent 7-day reminder not repeated', !in_array('Ana Popescu', array_column($run['due'], 'name'), true));
$run = Reminders::run(true, new DateTimeImmutable('2026-10-18'));
check('day-of reminder still due', in_array('Ana Popescu', array_column($run['due'], 'name'), true));

echo "OAuth\n";
[$st, $client] = OAuth::register(['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'client_name' => 'Claude']);
check('register public client', $st === 201 && isset($client['client_id']) && !isset($client['client_secret']));
[$st] = OAuth::register(['redirect_uris' => ['http://evil.example/cb']]);
check('rejects non-https redirect', $st === 400);
$verifier = random_token(40);
$challenge = b64url(hash('sha256', $verifier, true));
$code = OAuth::issueCode(OAuth::client($client['client_id']), 'https://claude.ai/api/mcp/auth_callback', $challenge, OAuth::resourceUrl());
[$st, $bad] = OAuth::token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => 'wrong', 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback'], null);
check('bad PKCE rejected', $st === 400);
[$st] = OAuth::token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier], null);
check('code is single-use', $st === 400);
$code = OAuth::issueCode(OAuth::client($client['client_id']), 'https://claude.ai/api/mcp/auth_callback', $challenge, OAuth::resourceUrl());
[$st, $tok] = OAuth::token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client['client_id'], 'code_verifier' => $verifier, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback'], null);
check('code exchange issues tokens', $st === 200 && isset($tok['access_token'], $tok['refresh_token']));
check('access token validates', OAuth::validateAccessToken($tok['access_token']));
check('random token rejected', !OAuth::validateAccessToken('nope'));
[$st, $tok2] = OAuth::token(['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $client['client_id']], null);
check('refresh works', $st === 200);
[$st] = OAuth::token(['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $client['client_id']], null);
check('refresh token rotates', $st === 400);
check('connections listed', count(OAuth::connections()) === 1);
OAuth::revokeAll();
check('revoke kills access token', !OAuth::validateAccessToken($tok2['access_token']));

echo "MCP\n";
$mcp = new Mcp($b);
$init = $mcp->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
check('initialize echoes protocol', $init['result']['protocolVersion'] === '2025-06-18');
check('notification returns null', $mcp->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null);
$list = $mcp->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
check('6 tools', count($list['result']['tools']) === 6);
$add = $mcp->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'add_birthday', 'arguments' => ['name' => 'Ana Popescu', 'day' => 18, 'month' => 10]]]);
check('duplicate add detected', strpos($add['result']['content'][0]['text'], 'already_exists') !== false);
$add = $mcp->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'add_birthday', 'arguments' => ['name' => 'Ana Marin', 'day' => 2, 'month' => 3]]]);
check('add reports similar names', strpos($add['result']['content'][0]['text'], 'similar_names') !== false);
$bad = $mcp->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'add_birthday', 'arguments' => ['name' => 'X', 'day' => 40, 'month' => 1]]]);
check('validation error is tool error', $bad['result']['isError'] === true);
$unknown = $mcp->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
check('unknown tool is JSON-RPC error', ($unknown['error']['code'] ?? 0) === -32602);
check('garbage message', ($mcp->handle('x')['error']['code'] ?? 0) === -32600);

// cleanup
exec('rm -rf ' . escapeshellarg($tmp));
echo $failures ? "\n$failures failure(s)\n" : "\nAll good.\n";
exit($failures ? 1 : 0);
