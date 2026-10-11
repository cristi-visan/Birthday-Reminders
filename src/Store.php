<?php
declare(strict_types=1);

/**
 * Tiny document store: each collection is one JSON file of documents keyed by id.
 * Writes take an exclusive lock, so the web UI, MCP and cron can't clobber each other.
 * The birthdays collection gets a daily backup (last 30 kept).
 */
final class Store
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create data directory: ' . $this->dir);
        }
        $this->protect($this->dir);
    }

    public function dir(string $sub = ''): string
    {
        if ($sub === '') {
            return $this->dir;
        }
        $path = $this->dir . '/' . $sub;
        if (!is_dir($path)) {
            @mkdir($path, 0750, true);
            $this->protect($path);
        }
        return $path;
    }

    public function read(string $collection): array
    {
        $path = $this->path($collection);
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read ' . $collection);
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Read-modify-write under an exclusive lock. $fn receives the data by reference
     * and its return value is passed back to the caller.
     */
    public function mutate(string $collection, callable $fn)
    {
        $path = $this->path($collection);
        $fh = fopen($path, 'c+b');
        if ($fh === false) {
            throw new RuntimeException('Cannot write ' . $collection);
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            if ($collection === 'birthdays' && $raw !== '' && $raw !== false) {
                $this->backup($collection, (string) $raw);
            }
            $result = $fn($data);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new RuntimeException('Cannot encode ' . $collection);
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function path(string $collection): string
    {
        if (!preg_match('/^[a-z_]+$/', $collection)) {
            throw new InvalidArgumentException('Bad collection name');
        }
        return $this->dir . '/' . $collection . '.json';
    }

    private function backup(string $collection, string $raw): void
    {
        $dir = $this->dir('backups');
        $file = $dir . '/' . $collection . '-' . date('Y-m-d') . '.json';
        if (is_file($file)) {
            return;
        }
        @file_put_contents($file, $raw);
        $files = glob($dir . '/' . $collection . '-*.json') ?: [];
        sort($files);
        while (count($files) > 30) {
            @unlink(array_shift($files));
        }
    }

    private function protect(string $dir): void
    {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', DENY_HTACCESS);
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }
}
