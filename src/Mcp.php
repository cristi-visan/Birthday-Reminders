<?php
declare(strict_types=1);

/** MCP server (Streamable HTTP, JSON responses) exposing the birthdays tools. */
final class Mcp
{
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private Birthdays $birthdays;

    public function __construct(Birthdays $birthdays)
    {
        $this->birthdays = $birthdays;
    }

    /** Handles one JSON-RPC message; returns a response array, or null for notifications. */
    public function handle($msg): ?array
    {
        if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || !isset($msg['method'])) {
            return self::error(is_array($msg) ? ($msg['id'] ?? null) : null, -32600, 'Invalid Request');
        }
        $isNotification = !array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        try {
            switch ($msg['method']) {
                case 'initialize':
                    $result = $this->initialize($params);
                    break;
                case 'ping':
                    $result = new stdClass();
                    break;
                case 'tools/list':
                    $result = ['tools' => self::tools()];
                    break;
                case 'tools/call':
                    $result = $this->callTool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []));
                    break;
                case 'resources/list':
                    $result = ['resources' => []];
                    break;
                case 'prompts/list':
                    $result = ['prompts' => []];
                    break;
                default:
                    if ($isNotification) {
                        return null;
                    }
                    return self::error($id, -32601, 'Method not found: ' . $msg['method']);
            }
        } catch (UnknownToolException $e) {
            return $isNotification ? null : self::error($id, -32602, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[birthdays mcp] ' . $e);
            return $isNotification ? null : self::error($id, -32603, 'Internal error');
        }
        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'birthdays', 'title' => 'Birthdays', 'version' => APP_VERSION],
            'instructions' => 'Personal birthday tracker. Each birthday has a name, day and month, an optional birth year, '
                . 'and optional relation, tags, notes and gift ideas. Use upcoming_birthdays for "who\'s next" questions. '
                . 'Before adding someone, call list_birthdays with their name to avoid duplicates; update instead if they exist. '
                . 'Today is ' . date('l j F Y') . ' (' . date_default_timezone_get() . ').',
        ];
    }

    public static function tools(): array
    {
        $fields = [
            'name' => ['type' => 'string', 'description' => 'Full name as you want it shown.'],
            'day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31],
            'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
            'year' => ['type' => ['integer', 'null'], 'description' => 'Birth year if known (enables age). Null or omit if unknown.'],
            'relation' => ['type' => 'string', 'description' => 'e.g. "friend", "sister", "client – Addinol".'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Free-form labels, e.g. ["family", "work"].'],
            'notes' => ['type' => 'string'],
            'gift_ideas' => ['type' => 'string'],
        ];
        $read = ['readOnlyHint' => true, 'openWorldHint' => false];
        return [
            [
                'name' => 'upcoming_birthdays',
                'title' => 'Upcoming birthdays',
                'description' => 'Birthdays in the next N days (default 30), soonest first, with days_until, next_date and the age they turn.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 366, 'default' => 30],
                ]],
                'annotations' => $read,
            ],
            [
                'name' => 'list_birthdays',
                'title' => 'List / search birthdays',
                'description' => 'All saved birthdays sorted by next occurrence. Optional filters: query (matches name, relation, tags, notes, gift ideas), tag, month (1-12).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string'],
                    'tag' => ['type' => 'string'],
                    'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                ]],
                'annotations' => $read,
            ],
            [
                'name' => 'get_birthday',
                'title' => 'Get a birthday',
                'description' => 'One birthday by id.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
                'annotations' => $read,
            ],
            [
                'name' => 'add_birthday',
                'title' => 'Add a birthday',
                'description' => 'Save a new birthday. Refuses exact duplicates (same name and date) and reports people with similar names.',
                'inputSchema' => ['type' => 'object', 'properties' => $fields, 'required' => ['name', 'day', 'month']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'update_birthday',
                'title' => 'Update a birthday',
                'description' => 'Change fields of an existing birthday. Only the fields you pass are changed; pass year: null to clear it. Tags replace the existing list.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']] + $fields, 'required' => ['id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'delete_birthday',
                'title' => 'Delete a birthday',
                'description' => 'Permanently remove a birthday by id. Confirm with the user first.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    private function callTool(string $name, array $args): array
    {
        try {
            switch ($name) {
                case 'upcoming_birthdays':
                    $days = (int) ($args['days'] ?? 30);
                    $list = $this->birthdays->upcoming($days);
                    return self::ok(['today' => date('Y-m-d'), 'days' => $days, 'count' => count($list), 'birthdays' => array_map([self::class, 'slim'], $list)]);

                case 'list_birthdays':
                    $month = isset($args['month']) ? (int) $args['month'] : null;
                    $list = $this->birthdays->list($args['query'] ?? null, $args['tag'] ?? null, $month);
                    return self::ok(['today' => date('Y-m-d'), 'count' => count($list), 'birthdays' => array_map([self::class, 'slim'], $list)]);

                case 'get_birthday':
                    $b = $this->birthdays->get((string) ($args['id'] ?? ''));
                    return $b ? self::ok(self::slim($b)) : self::fail('No birthday with that id.');

                case 'add_birthday':
                    foreach ($this->birthdays->similar((string) ($args['name'] ?? '')) as $s) {
                        if (mb_strtolower($s['name']) === mb_strtolower(trim((string) ($args['name'] ?? '')))
                            && (int) $s['day'] === (int) ($args['day'] ?? 0) && (int) $s['month'] === (int) ($args['month'] ?? 0)) {
                            return self::ok(['status' => 'already_exists', 'birthday' => self::slim($s)]);
                        }
                    }
                    $similar = $this->birthdays->similar((string) ($args['name'] ?? ''));
                    $b = $this->birthdays->create($args);
                    $out = ['status' => 'created', 'birthday' => self::slim($b)];
                    if ($similar) {
                        $out['similar_names'] = array_map(static fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'date' => $s['date_label']], $similar);
                    }
                    return self::ok($out);

                case 'update_birthday':
                    $id = (string) ($args['id'] ?? '');
                    unset($args['id']);
                    $b = $this->birthdays->update($id, $args);
                    return $b ? self::ok(['status' => 'updated', 'birthday' => self::slim($b)]) : self::fail('No birthday with that id.');

                case 'delete_birthday':
                    $doc = $this->birthdays->delete((string) ($args['id'] ?? ''));
                    return $doc ? self::ok(['status' => 'deleted', 'name' => $doc['name']]) : self::fail('No birthday with that id.');
            }
        } catch (InvalidArgumentException $e) {
            return self::fail($e->getMessage());
        }
        throw new UnknownToolException('Unknown tool: ' . $name);
    }

    private static function slim(array $b): array
    {
        return [
            'id' => $b['id'],
            'name' => $b['name'],
            'date' => $b['date_label'],
            'day' => $b['day'],
            'month' => $b['month'],
            'year' => $b['year'],
            'next_date' => $b['next_date'] . ' (' . $b['next_weekday'] . ')',
            'days_until' => $b['days_until'],
            'turns' => $b['turns'],
            'relation' => $b['relation'],
            'tags' => $b['tags'],
            'notes' => $b['notes'],
            'gift_ideas' => $b['gift_ideas'],
        ];
    }

    private static function ok($data): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];
    }

    private static function fail(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    public static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

final class UnknownToolException extends RuntimeException
{
}
