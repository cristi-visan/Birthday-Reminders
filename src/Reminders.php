<?php
declare(strict_types=1);

/**
 * Daily reminder run. For each birthday, picks the closest configured offset (e.g. 7 or 0 days)
 * that applies and sends it once per occurrence — so a missed cron day still catches up,
 * and running the cron twice never double-sends.
 */
final class Reminders
{
    /** @return array{due: array, message: ?string, sent: bool, error: ?string} */
    public static function run(bool $dryRun = false, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? today();
        $offsets = array_values(array_unique(array_map('intval', (array) cfg('reminder_days', [7, 0]))));
        sort($offsets);
        $sent = store()->read('reminders_sent');

        $due = [];
        foreach ((new Birthdays(store()))->list(null, null, null, $today) as $b) {
            $offset = null;
            foreach ($offsets as $o) {
                if ($o >= $b['days_until']) {
                    $offset = $o;
                    break;
                }
            }
            if ($offset === null) {
                continue;
            }
            $key = $b['id'] . '|' . $b['next_date'] . '|' . $offset;
            if (isset($sent[$key])) {
                continue;
            }
            $b['_key'] = $key;
            $due[] = $b;
        }

        if (!$due) {
            return ['due' => [], 'message' => null, 'sent' => false, 'error' => null];
        }

        $message = self::format($due, $today);
        if ($dryRun) {
            return ['due' => $due, 'message' => $message, 'sent' => false, 'error' => null];
        }

        $result = Slack::send($message);
        if ($result['ok']) {
            $stamp = $today->format('Y-m-d');
            $cutoff = $today->modify('-400 days')->format('Y-m-d');
            store()->mutate('reminders_sent', static function (array &$data) use ($due, $stamp, $cutoff) {
                foreach ($data as $k => $date) {
                    if ($date < $cutoff) {
                        unset($data[$k]);
                    }
                }
                foreach ($due as $b) {
                    $data[$b['_key']] = $stamp;
                }
            });
        }
        return ['due' => $due, 'message' => $message, 'sent' => $result['ok'], 'error' => $result['error']];
    }

    public static function format(array $due, DateTimeImmutable $today): string
    {
        $groups = [];
        foreach ($due as $b) {
            $groups[$b['days_until']][] = $b;
        }
        ksort($groups);

        $lines = ['🎂 *Birthday reminders* — ' . $today->format('D j M')];
        foreach ($groups as $days => $people) {
            $date = $today->modify("+$days days");
            if ($days === 0) {
                $heading = '*Today*';
            } elseif ($days === 1) {
                $heading = '*Tomorrow* (' . $date->format('D j M') . ')';
            } else {
                $heading = "*In $days days* (" . $date->format('D j M') . ')';
            }
            $lines[] = '';
            $lines[] = $heading;
            foreach ($people as $b) {
                $line = '• *' . Slack::escape($b['name']) . '*';
                if ($b['turns'] !== null) {
                    $line .= $days === 0 ? ' turns ' . $b['turns'] . ' today 🎉' : ' turns ' . $b['turns'];
                } elseif ($days === 0) {
                    $line .= ' — birthday today 🎉';
                }
                if ($b['relation'] !== '') {
                    $line .= ' _(' . Slack::escape($b['relation']) . ')_';
                }
                $lines[] = $line;
                if ($days > 0 && $b['gift_ideas'] !== '') {
                    $lines[] = '    🎁 ' . Slack::escape(self::oneLine($b['gift_ideas']));
                }
                if ($b['notes'] !== '') {
                    $lines[] = '    📝 ' . Slack::escape(self::oneLine($b['notes']));
                }
            }
        }
        $lines[] = '';
        $lines[] = '<' . base_url() . '|Open birthdays>';
        return implode("\n", $lines);
    }

    private static function oneLine(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return mb_strlen($text) > 280 ? mb_substr($text, 0, 277) . '…' : $text;
    }
}
