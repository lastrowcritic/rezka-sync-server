<?php

declare(strict_types=1);

final class WatchSyncApi
{
    private const SESSION_LIFETIME = 30 * 24 * 60 * 60;
    private const AUTH_WINDOW = 15 * 60;
    private const PASSWORD_ITERATIONS = 250000;
    private const MAX_RECORDS = 500;
    private const MAX_PAYLOAD_BYTES = 1500000;

    private readonly string $driver;

    /** New accounts can only be created while $allowRegistration is on. */
    public function __construct(private readonly PDO $database, private readonly string $sessionPepper, private readonly bool $allowRegistration = false)
    {
        $this->driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /** $basePath is the folder the API is installed in, such as /rezka-sync/public. Empty when it is the web root. */
    public function handle(string $basePath = ''): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // In a subfolder, the routes follow the folder's address, with or without the public folder in it.
        foreach (array_unique([$basePath, (string) preg_replace('#/public$#', '', $basePath)]) as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        if ($method === 'OPTIONS') {
            $this->respond([], 204, $origin);
        }
        if ($method === 'GET' && $path === '/health') {
            $this->respond(['ok' => true, 'service' => 'watch-sync', 'registration' => $this->allowRegistration], 200, $origin);
        }

        try {
            if ($method === 'POST' && in_array($path, ['/v1/auth/register', '/v1/auth/login'], true)) {
                $this->authenticate($path, $origin);
            }

            $user = $this->authorizedUser();
            if ($user === null) {
                $this->fail('Sign in to sync viewing progress.', 401, $origin);
            }

            if ($method === 'POST' && $path === '/v1/auth/logout') {
                $token = $this->bearerToken();
                $statement = $this->database->prepare('DELETE FROM sessions WHERE token_hash = ?');
                $statement->execute([$this->digest($this->sessionPepper . ':' . $token)]);
                $this->respond(['ok' => true], 200, $origin);
            }

            if ($method === 'GET' && $path === '/v1/history') {
                $this->respond($this->historySnapshot($user['id'], $user['history_cleared_at']), 200, $origin);
            }

            if ($method === 'PUT' && $path === '/v1/history') {
                $this->putHistory($user, $origin);
            }

            if ($method === 'DELETE' && $path === '/v1/history') {
                $clearedAt = (int) floor(microtime(true) * 1000);
                $this->database->beginTransaction();
                $update = $this->database->prepare('UPDATE users SET history_cleared_at = ? WHERE id = ?');
                $update->execute([$clearedAt, $user['id']]);
                $delete = $this->database->prepare('DELETE FROM watch_records WHERE user_id = ?');
                $delete->execute([$user['id']]);
                $this->database->commit();
                $this->respond(['records' => [], 'clearedAt' => $clearedAt], 200, $origin);
            }

            $this->fail('This API route was not found.', 404, $origin);
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            error_log('Watch sync request failed: ' . $error::class);
            $this->fail('The sync service could not complete this request.', 500, $origin);
        }
    }

    private function authenticate(string $path, string $origin): never
    {
        if ($path === '/v1/auth/register' && !$this->allowRegistration) {
            $this->fail('This server does not accept new accounts. Ask its owner to turn on sign-ups.', 403, $origin);
        }
        try {
            $body = $this->readJson();
        } catch (LengthException) {
            $this->fail('Request is too large.', 400, $origin);
        } catch (JsonException) {
            $this->fail('Invalid request.', 400, $origin);
        }

        $input = get_object_vars($body);
        $emailValue = $input['email'] ?? null;
        $email = is_string($emailValue) ? mb_strtolower(trim($emailValue)) : '';
        $passwordValue = $input['password'] ?? null;
        $password = is_string($passwordValue) ? $passwordValue : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 10 || strlen($password) > 256) {
            $this->fail('Enter a valid email and a password with at least 10 characters.', 400, $origin);
        }
        if (!$this->allowAuthAttempt($email)) {
            $this->fail('Too many attempts. Try again later.', 429, $origin);
        }

        if ($path === '/v1/auth/register') {
            $salt = random_bytes(16);
            $id = self::uuid();
            $now = time();
            try {
                $insert = $this->database->prepare(
                    'INSERT INTO users (id, email, password_salt, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                );
                $insert->execute([
                    $id,
                    $email,
                    self::base64UrlEncode($salt),
                    self::base64UrlEncode(hash_pbkdf2('sha256', $password, $salt, self::PASSWORD_ITERATIONS, 32, true)),
                    $now,
                ]);
            } catch (PDOException) {
                $this->fail('An account with this email may already exist.', 409, $origin);
            }
            $this->respond(['user' => ['id' => $id, 'email' => $email]] + $this->issueSession($id), 201, $origin);
        }

        $query = $this->database->prepare('SELECT id, email, password_salt, password_hash FROM users WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch();
        $salt = is_array($user) ? self::base64UrlDecode($user['password_salt']) : str_repeat("\0", 16);
        $actual = self::base64UrlEncode(hash_pbkdf2('sha256', $password, $salt, self::PASSWORD_ITERATIONS, 32, true));
        if (!is_array($user) || !hash_equals((string) $user['password_hash'], $actual)) {
            $this->fail('Email or password did not match.', 401, $origin);
        }
        $this->respond(['user' => ['id' => $user['id'], 'email' => $user['email']]] + $this->issueSession($user['id']), 200, $origin);
    }

    private function putHistory(array $user, string $origin): never
    {
        try {
            $body = $this->readJson();
        } catch (LengthException) {
            $this->fail('History upload is too large.', 400, $origin);
        } catch (JsonException) {
            $this->fail('Invalid request.', 400, $origin);
        }

        $input = get_object_vars($body);
        $records = $input['records'] ?? null;
        if (!is_array($records) || count($records) > self::MAX_RECORDS) {
            $this->fail('History must contain at most 500 records.', 400, $origin);
        }

        $unique = [];
        foreach ($records as $value) {
            $record = WatchRecord::clean($value);
            if ($record === null) {
                $this->fail('A viewing record was incomplete or invalid.', 400, $origin);
            }
            if ($record['updatedAt'] <= (int) $user['history_cleared_at']) {
                continue;
            }
            $key = WatchRecord::key($record);
            if (!isset($unique[$key]) || $unique[$key]['updatedAt'] < $record['updatedAt']) {
                $unique[$key] = $record;
            }
        }

        $this->database->beginTransaction();
        if ($this->driver === 'sqlite') {
            $upsert = $this->database->prepare(
                'INSERT INTO watch_records (user_id, record_key, media_id, updated_at, record_json) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(user_id, record_key) DO UPDATE SET
                    media_id = excluded.media_id,
                    updated_at = excluded.updated_at,
                    record_json = excluded.record_json
                 WHERE excluded.updated_at > watch_records.updated_at',
            );
        } else {
            $upsert = $this->database->prepare(
                'INSERT INTO watch_records (user_id, record_key, media_id, updated_at, record_json) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    media_id = IF(VALUES(updated_at) > updated_at, VALUES(media_id), media_id),
                    record_json = IF(VALUES(updated_at) > updated_at, VALUES(record_json), record_json),
                    updated_at = GREATEST(updated_at, VALUES(updated_at))',
            );
        }
        foreach ($unique as $key => $record) {
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $upsert->execute([$user['id'], $key, (string) $record['item']['id'], $record['updatedAt'], $encoded]);
        }

        $prune = $this->database->prepare(
            'DELETE FROM watch_records WHERE user_id = ? AND record_key NOT IN
             (SELECT record_key FROM (SELECT record_key FROM watch_records WHERE user_id = ? ORDER BY updated_at DESC LIMIT ?) AS recent_records)',
        );
        $prune->execute([$user['id'], $user['id'], self::MAX_RECORDS]);
        $this->database->commit();
        $this->respond($this->historySnapshot($user['id'], $user['history_cleared_at']), 200, $origin);
    }

    private function historySnapshot(string $userId, int|string $clearedAt): array
    {
        $query = $this->database->prepare(
            'SELECT record_json FROM watch_records WHERE user_id = ? ORDER BY updated_at DESC LIMIT ?',
        );
        $query->bindValue(1, $userId, PDO::PARAM_STR);
        $query->bindValue(2, self::MAX_RECORDS, PDO::PARAM_INT);
        $query->execute();
        $records = [];
        foreach ($query->fetchAll() as $row) {
            $records[] = json_decode((string) $row['record_json'], false, 512, JSON_THROW_ON_ERROR);
        }
        return ['records' => $records, 'clearedAt' => (int) $clearedAt];
    }

    private function allowAuthAttempt(string $email): bool
    {
        $now = time();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $keys = [$this->digest('ip:' . $ip), $this->digest('email:' . $email)];
        $this->database->beginTransaction();

        $expired = $this->database->prepare('DELETE FROM auth_limits WHERE window_started_at < ?');
        $expired->execute([$now - 86400]);

        $insertSql = $this->driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO auth_limits (key_hash, window_started_at, attempts) VALUES (?, ?, 0)'
            : 'INSERT IGNORE INTO auth_limits (key_hash, window_started_at, attempts) VALUES (?, ?, 0)';
        $insert = $this->database->prepare($insertSql);
        foreach ($keys as $key) {
            $insert->execute([$key, $now]);
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $locking = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->database->prepare(
            'SELECT key_hash, window_started_at, attempts FROM auth_limits WHERE key_hash IN (' . $placeholders . ')' . $locking,
        );
        $query->execute($keys);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            $rows[$row['key_hash']] = $row;
        }

        foreach ($keys as $index => $key) {
            $row = $rows[$key] ?? null;
            $limit = $index === 0 ? 30 : 10;
            if (is_array($row) && $now - (int) $row['window_started_at'] < self::AUTH_WINDOW && (int) $row['attempts'] >= $limit) {
                $this->database->commit();
                return false;
            }
        }

        $update = $this->database->prepare(
            'UPDATE auth_limits SET window_started_at = ?, attempts = ? WHERE key_hash = ?',
        );
        foreach ($keys as $key) {
            $row = $rows[$key];
            $expired = $now - (int) $row['window_started_at'] >= self::AUTH_WINDOW;
            $update->execute([$expired ? $now : (int) $row['window_started_at'], $expired ? 1 : (int) $row['attempts'] + 1, $key]);
        }
        $this->database->commit();
        return true;
    }

    private function issueSession(string $userId): array
    {
        $token = self::base64UrlEncode(random_bytes(32));
        $now = time();
        $expiresAt = $now + self::SESSION_LIFETIME;
        $insert = $this->database->prepare(
            'INSERT INTO sessions (token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?)',
        );
        $insert->execute([$this->digest($this->sessionPepper . ':' . $token), $userId, $expiresAt, $now]);
        return ['token' => $token, 'expiresAt' => $expiresAt * 1000];
    }

    private function authorizedUser(): ?array
    {
        $token = $this->bearerToken();
        if ($token === null) {
            return null;
        }
        $query = $this->database->prepare(
            'SELECT users.id, users.history_cleared_at FROM sessions
             INNER JOIN users ON users.id = sessions.user_id
             WHERE sessions.token_hash = ? AND sessions.expires_at > ?',
        );
        $query->execute([$this->digest($this->sessionPepper . ':' . $token), time()]);
        $user = $query->fetch();
        return is_array($user) ? $user : null;
    }

    private function bearerToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/\ABearer ([A-Za-z0-9_-]{40,60})\z/', $authorization, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function readJson(): stdClass
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_PAYLOAD_BYTES) {
            throw new LengthException('payload-too-large');
        }
        $text = file_get_contents('php://input');
        if (!is_string($text) || strlen($text) > self::MAX_PAYLOAD_BYTES) {
            throw new LengthException('payload-too-large');
        }
        $body = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        if (!$body instanceof stdClass) {
            throw new JsonException('Expected a JSON object.');
        }
        return $body;
    }

    private function digest(string $value): string
    {
        return self::base64UrlEncode(hash('sha256', $value, true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return is_string($decoded) ? $decoded : '';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function respond(array $body, int $status = 200, string $origin = '*'): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: authorization, content-type');
        header('Vary: Origin');
        if ($status !== 204) {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        exit;
    }

    private function fail(string $message, int $status, string $origin = '*'): never
    {
        $this->respond(['error' => $message], $status, $origin);
    }
}
