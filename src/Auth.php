<?php
declare(strict_types=1);

/** Single-user password login for the web UI and the OAuth consent screen. */
final class Auth
{
    private const MAX_FAILURES = 8;
    private const WINDOW = 900; // seconds

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = 60 * 60 * 24 * 30;
        // Own session folder so shared-hosting cleanup doesn't log you out early.
        session_save_path(store()->dir('sessions'));
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        session_name('bdsess');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        self::startSession();
        return ($_SESSION['auth'] ?? false) === true;
    }

    public static function passwordConfigured(): bool
    {
        $pw = (string) cfg('password', '');
        return $pw !== '' && $pw !== 'change-me';
    }

    public static function verifyPassword(string $password): bool
    {
        if (!self::passwordConfigured() || $password === '') {
            return false;
        }
        $configured = (string) cfg('password');
        if (preg_match('/^\$(2y|2a|argon2i|argon2id)\$/', $configured)) {
            return password_verify($password, $configured);
        }
        return hash_equals($configured, $password);
    }

    /** Checks the password with brute-force throttling per IP. Returns null when locked out. */
    public static function attempt(string $password): ?bool
    {
        $ip = client_ip();
        $now = time();
        $locked = store()->mutate('security', static function (array &$data) use ($ip, $now) {
            foreach ($data as $k => $row) {
                if ($row['first'] < $now - self::WINDOW) {
                    unset($data[$k]);
                }
            }
            return ($data[$ip]['count'] ?? 0) >= self::MAX_FAILURES;
        });
        if ($locked) {
            return null;
        }
        if (self::verifyPassword($password)) {
            store()->mutate('security', static function (array &$data) use ($ip) {
                unset($data[$ip]);
            });
            return true;
        }
        usleep(400000);
        store()->mutate('security', static function (array &$data) use ($ip, $now) {
            $data[$ip] = ['count' => ($data[$ip]['count'] ?? 0) + 1, 'first' => $data[$ip]['first'] ?? $now];
        });
        return false;
    }

    public static function login(): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(24);
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
