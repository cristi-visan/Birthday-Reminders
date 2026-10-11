<?php
declare(strict_types=1);

/**
 * Birthday documents:
 *   id, name, day, month, year|null, relation, tags[], notes, gift_ideas, created_at, updated_at
 * Read results are "enriched" with next_date, days_until, turns (age on next birthday) and age.
 */
final class Birthdays
{
    private const COLLECTION = 'birthdays';
    private const TEXT_LIMITS = ['name' => 120, 'relation' => 60, 'notes' => 2000, 'gift_ideas' => 2000];

    private Store $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    /** All birthdays, soonest first. Optional filters: free-text query, tag, month. */
    public function list(?string $query = null, ?string $tag = null, ?int $month = null, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? today();
        $query = $query !== null ? mb_strtolower(trim($query)) : '';
        $tag = $tag !== null ? mb_strtolower(trim($tag)) : '';

        $out = [];
        foreach ($this->store->read(self::COLLECTION) as $doc) {
            if ($month && (int) $doc['month'] !== $month) {
                continue;
            }
            if ($tag !== '' && !in_array($tag, array_map('mb_strtolower', $doc['tags'] ?? []), true)) {
                continue;
            }
            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $doc['name'] ?? '', $doc['relation'] ?? '', implode(' ', $doc['tags'] ?? []),
                    $doc['notes'] ?? '', $doc['gift_ideas'] ?? '',
                ]));
                $terms = preg_split('/\s+/', $query) ?: [];
                foreach ($terms as $term) {
                    if ($term !== '' && mb_strpos($haystack, $term) === false) {
                        continue 2;
                    }
                }
            }
            $out[] = self::enrich($doc, $today);
        }
        usort($out, static function ($a, $b) {
            return [$a['days_until'], mb_strtolower($a['name'])] <=> [$b['days_until'], mb_strtolower($b['name'])];
        });
        return $out;
    }

    public function upcoming(int $days, ?DateTimeImmutable $today = null): array
    {
        $days = max(0, min(366, $days));
        return array_values(array_filter(
            $this->list(null, null, null, $today),
            static fn ($b) => $b['days_until'] <= $days
        ));
    }

    public function get(string $id, ?DateTimeImmutable $today = null): ?array
    {
        $docs = $this->store->read(self::COLLECTION);
        return isset($docs[$id]) ? self::enrich($docs[$id], $today ?? today()) : null;
    }

    /** Birthdays whose name shares a word with $name (case-insensitive) — for duplicate checks. */
    public function similar(string $name): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($name))) ?: [], static fn ($w) => mb_strlen($w) > 1);
        if (!$words) {
            return [];
        }
        $out = [];
        foreach ($this->store->read(self::COLLECTION) as $doc) {
            $docWords = preg_split('/\s+/', mb_strtolower($doc['name'])) ?: [];
            if (array_intersect($words, $docWords)) {
                $out[] = self::enrich($doc, today());
            }
        }
        return $out;
    }

    public function create(array $input): array
    {
        $fields = self::normalize($input, false);
        $now = date(DATE_ATOM);
        $doc = array_merge(['id' => bin2hex(random_bytes(6))], $fields, ['created_at' => $now, 'updated_at' => $now]);
        $this->store->mutate(self::COLLECTION, static function (array &$data) use ($doc) {
            $data[$doc['id']] = $doc;
        });
        return self::enrich($doc, today());
    }

    public function update(string $id, array $input): ?array
    {
        $fields = self::normalize($input, true);
        $doc = $this->store->mutate(self::COLLECTION, static function (array &$data) use ($id, $fields) {
            if (!isset($data[$id])) {
                return null;
            }
            $merged = array_merge($data[$id], $fields);
            self::assertValidDate((int) $merged['day'], (int) $merged['month'], $merged['year']);
            $merged['updated_at'] = date(DATE_ATOM);
            $data[$id] = $merged;
            return $merged;
        });
        return $doc ? self::enrich($doc, today()) : null;
    }

    public function delete(string $id): ?array
    {
        return $this->store->mutate(self::COLLECTION, static function (array &$data) use ($id) {
            if (!isset($data[$id])) {
                return null;
            }
            $doc = $data[$id];
            unset($data[$id]);
            return $doc;
        });
    }

    public function count(): int
    {
        return count($this->store->read(self::COLLECTION));
    }

    public function export(): array
    {
        return array_values($this->store->read(self::COLLECTION));
    }

    // ---------------------------------------------------------------------------------

    public static function enrich(array $doc, DateTimeImmutable $today): array
    {
        $day = (int) $doc['day'];
        $month = (int) $doc['month'];
        $year = (int) $today->format('Y');
        $next = self::occurrence($day, $month, $year);
        if ($next < $today) {
            $next = self::occurrence($day, $month, $year + 1);
        }
        $daysUntil = (int) $today->diff($next)->days;
        $turns = !empty($doc['year']) ? (int) $next->format('Y') - (int) $doc['year'] : null;

        $doc['date_label'] = $day . ' ' . MONTH_NAMES[$month];
        $doc['next_date'] = $next->format('Y-m-d');
        $doc['next_weekday'] = $next->format('l');
        $doc['days_until'] = $daysUntil;
        $doc['is_today'] = $daysUntil === 0;
        $doc['turns'] = $turns;
        $doc['age'] = $turns === null ? null : ($daysUntil === 0 ? $turns : $turns - 1);
        return $doc;
    }

    /** The date this birthday falls on in $year (29 Feb → 28 Feb in non-leap years). */
    public static function occurrence(int $day, int $month, int $year): DateTimeImmutable
    {
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private static function normalize(array $input, bool $partial): array
    {
        $out = [];
        $has = static fn (string $k) => array_key_exists($k, $input);

        if (!$partial || $has('name')) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Name is required.');
            }
            $out['name'] = $name;
        }
        foreach (['day' => [1, 31], 'month' => [1, 12]] as $key => [$min, $max]) {
            if (!$partial || $has($key)) {
                $v = $input[$key] ?? null;
                if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
                    throw new InvalidArgumentException(ucfirst($key) . " must be a whole number from $min to $max.");
                }
                $out[$key] = (int) $v;
            }
        }
        if (!$partial || $has('year')) {
            $v = $input['year'] ?? null;
            if ($v === null || $v === '' || $v === 0 || $v === '0') {
                $out['year'] = null;
            } else {
                $current = (int) date('Y');
                if (!is_numeric($v) || (int) $v < 1900 || (int) $v > $current) {
                    throw new InvalidArgumentException("Year must be between 1900 and $current, or empty if unknown.");
                }
                $out['year'] = (int) $v;
            }
        }
        foreach (['relation', 'notes', 'gift_ideas'] as $key) {
            if (!$partial || $has($key)) {
                $out[$key] = trim((string) ($input[$key] ?? ''));
            }
        }
        if (!$partial || $has('tags')) {
            $tags = $input['tags'] ?? [];
            if (is_string($tags)) {
                $tags = explode(',', $tags);
            }
            if (!is_array($tags)) {
                throw new InvalidArgumentException('Tags must be a list of strings.');
            }
            $clean = [];
            foreach ($tags as $t) {
                $t = trim((string) $t);
                if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $clean), true)) {
                    $clean[] = mb_substr($t, 0, 40);
                }
            }
            $out['tags'] = array_slice($clean, 0, 15);
        }
        foreach (self::TEXT_LIMITS as $key => $limit) {
            if (isset($out[$key]) && mb_strlen($out[$key]) > $limit) {
                throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . " is too long (max $limit characters).");
            }
        }
        if (!$partial) {
            self::assertValidDate($out['day'], $out['month'], $out['year']);
        }
        return $out;
    }

    private static function assertValidDate(int $day, int $month, $year): void
    {
        // 2000 is a leap year, so 29 Feb is accepted when the year is unknown.
        if (!checkdate($month, $day, $year ? (int) $year : 2000)) {
            throw new InvalidArgumentException(sprintf('%d %s%s is not a real date.', $day, MONTH_NAMES[$month], $year ? " $year" : ''));
        }
    }
}
