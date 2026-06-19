<?php

declare(strict_types=1);

require_once __DIR__ . '/app_config.php';

// Security Headers
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: same-origin');
}

// Secure Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

// CSRF Helpers
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function sketch_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

function sketch_verify_csrf_token(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Encryption Helpers
if (!defined('SKETCH_SECRET_KEY')) {
    define(
        'SKETCH_SECRET_KEY',
        (string) sketch_config('app.secret_key', 'change-this-secret-key-in-config')
    );
}

function sketch_get_room_encryption_key(string $room): string
{
    $salt = $room;
    return hash_pbkdf2('sha256', SKETCH_SECRET_KEY, $salt, 1000, 32, true);
}

function sketch_encrypt_state(string $plaintext, string $room): string
{
    $key = sketch_get_room_encryption_key($room);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
}

function sketch_decrypt_state(string $ciphertextBase64, string $room): ?string
{
    $key = sketch_get_room_encryption_key($room);
    $data = base64_decode($ciphertextBase64);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($data) <= $ivLength) {
        return null;
    }
    $iv = substr($data, 0, $ivLength);
    $ciphertext = substr($data, $ivLength);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plaintext !== false ? $plaintext : null;
}

// Rate Limiting Helper
function sketch_is_rate_limited(string $action, int $limit = 5, int $period = 60): bool
{
    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '') {
            return false;
        }
        
        $now = time();
        $cutoff = $now - $period;
        
        // Clean old entries
        $db->prepare("DELETE FROM rate_limits WHERE timestamp < ?")->execute([$cutoff]);
        
        // Count attempts
        $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND action = ? AND timestamp >= ?");
        $stmt->execute([$ip, $action, $cutoff]);
        $count = (int) $stmt->fetchColumn();
        
        if ($count >= $limit) {
            return true;
        }
        
        // Log attempt
        $db->prepare("INSERT INTO rate_limits (ip, action, timestamp) VALUES (?, ?, ?)")->execute([$ip, $action, $now]);
    } catch (Exception $e) {
        // Fallback to allowed in case of DB issues
    }
    return false;
}

function sketch_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
}

function sketch_base_url(): string
{
    $protocol = sketch_is_https() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $sketchDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $protocol . $host . ($sketchDir === '/' ? '' : $sketchDir);
}

function sketch_asset_base(): string
{
    return sketch_base_url();
}

function sketch_room_id(?string $value): string
{
    $room = trim((string) $value);
    if ($room === '') {
        return '';
    }

    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{1,63}\z/', $room)) {
        return '';
    }

    return $room;
}

function sketch_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function sketch_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function sketch_user_owns_room(int $userId, string $room): bool
{
    $room = sketch_room_id($room);
    if ($userId <= 0 || $room === '') {
        return false;
    }

    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $stmt = $db->prepare("SELECT id FROM rooms WHERE code = ? AND owner_user_id = ? LIMIT 1");
        $stmt->execute([$room, $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function sketch_record_room_membership(int $userId, string $room): void
{
    $room = sketch_room_id($room);
    if ($userId <= 0 || $room === '') {
        return;
    }

    try {
        global $db;
        require_once __DIR__ . '/db.php';

        $stmt = $db->prepare("SELECT owner_user_id FROM rooms WHERE code = ? LIMIT 1");
        $stmt->execute([$room]);
        $ownerUserId = $stmt->fetchColumn();
        if ($ownerUserId === false || (int) $ownerUserId === $userId) {
            return;
        }

        $now = time();
        $stmt = $db->prepare("INSERT INTO room_memberships (user_id, room_code, joined_at, last_joined_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_joined_at = VALUES(last_joined_at)");
        $stmt->execute([$userId, $room, $now, $now]);
    } catch (Exception $e) {
        // Ignore membership tracking failures.
    }
}

function sketch_remove_room_membership(int $userId, string $room): void
{
    $room = sketch_room_id($room);
    if ($userId <= 0 || $room === '') {
        return;
    }

    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $stmt = $db->prepare("DELETE FROM room_memberships WHERE user_id = ? AND room_code = ?");
        $stmt->execute([$userId, $room]);
    } catch (Exception $e) {
        // Ignore.
    }
}

function sketch_fetch_user_whiteboards(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        global $db;
        require_once __DIR__ . '/db.php';

        $boards = [];

        $stmt = $db->prepare("SELECT id, code, name, owner_user_id, created_at
            FROM rooms
            WHERE owner_user_id = ?
            ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $boards[$code] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => $code,
                'name' => (string) ($row['name'] ?? 'Untitled board'),
                'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
                'created_at' => (int) ($row['created_at'] ?? 0),
                'relation' => 'owned',
                'relation_at' => (int) ($row['created_at'] ?? 0),
                'is_owned' => true,
                'relationship_label' => 'OWNED',
            ];
        }

        $stmt = $db->prepare("SELECT r.id, r.code, r.name, r.owner_user_id, r.created_at, rm.joined_at, rm.last_joined_at
            FROM room_memberships rm
            INNER JOIN rooms r ON r.code = rm.room_code
            WHERE rm.user_id = ? AND r.owner_user_id <> ?
            ORDER BY rm.last_joined_at DESC");
        $stmt->execute([$userId, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '' || isset($boards[$code])) {
                continue;
            }

            $relationAt = (int) ($row['last_joined_at'] ?? $row['joined_at'] ?? $row['created_at'] ?? 0);
            $boards[$code] = [
                'id' => (int) ($row['id'] ?? 0),
                'code' => $code,
                'name' => (string) ($row['name'] ?? 'Untitled board'),
                'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
                'created_at' => (int) ($row['created_at'] ?? 0),
                'relation' => 'joined',
                'relation_at' => $relationAt,
                'is_owned' => false,
                'relationship_label' => 'JOINED',
            ];
        }

        $boards = array_values($boards);
        usort($boards, static function (array $a, array $b): int {
            $aTime = (int) ($a['relation_at'] ?? 0);
            $bTime = (int) ($b['relation_at'] ?? 0);
            if ($aTime !== $bTime) {
                return $bTime <=> $aTime;
            }

            $aOwned = !empty($a['is_owned']);
            $bOwned = !empty($b['is_owned']);
            if ($aOwned !== $bOwned) {
                return $aOwned ? -1 : 1;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $boards;
    } catch (Exception $e) {
        return [];
    }
}

function sketch_room_file(string $room): string
{
    return __DIR__ . '/rooms/' . $room . '.json';
}

function sketch_state_file(string $room): string
{
    return __DIR__ . '/rooms_data/' . $room . '.json';
}

function sketch_default_room_state(string $room): array
{
    return [
        'room' => $room,
        'revision' => 0,
        'updated_at' => time(),
        'strokes' => [],
        'messages' => [],
        'meta' => [
            'bounds' => null,
            'stroke_count' => 0,
        ],
    ];
}

function sketch_peer_ttl(): int
{
    return 70;
}

function sketch_idle_room_grace_period(): int
{
    return 120;
}

function sketch_with_locked_json_file(string $path, array $default, callable $callback): array
{
    $dir = dirname($path);
    sketch_ensure_dir($dir);

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open data file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock data file.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false) {
            $raw = '';
        }

        $decoded = null;
        if ($raw !== '') {
            if (strpos($raw, '{') === 0) {
                $decoded = json_decode($raw, true);
            } else {
                $room = basename($path, '.json');
                $decrypted = sketch_decrypt_state($raw, $room);
                $decoded = $decrypted ? json_decode($decrypted, true) : null;
            }
        }
        $data = is_array($decoded) ? $decoded : $default;

        $result = $callback($data);
        if (!is_array($result)) {
            $result = $data;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode data file.');
        }

        $room = basename($path, '.json');
        $encrypted = sketch_encrypt_state($encoded, $room);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encrypted);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function sketch_read_json_file(string $path, array $default): array
{
    if (!is_file($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }

    if (strpos($raw, '{') === 0) {
        $decoded = json_decode($raw, true);
    } else {
        $room = basename($path, '.json');
        $decrypted = sketch_decrypt_state($raw, $room);
        $decoded = $decrypted ? json_decode($decrypted, true) : null;
    }

    return is_array($decoded) ? $decoded : $default;
}

function sketch_read_room_state(string $room, array $default): array
{
    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $stmt = $db->prepare("SELECT state FROM room_states WHERE room = ? LIMIT 1");
        $stmt->execute([$room]);
        $row = $stmt->fetch();
        if ($row) {
            $stateStr = $row['state'];
            if (strpos($stateStr, '{') === 0) {
                $decoded = json_decode($stateStr, true);
            } else {
                $decrypted = sketch_decrypt_state($stateStr, $room);
                $decoded = $decrypted ? json_decode($decrypted, true) : null;
            }
            return is_array($decoded) ? $decoded : $default;
        }
    } catch (Exception $e) {
        // Fall back to default
    }
    return $default;
}

function sketch_with_locked_room_state(string $room, array $default, callable $callback): array
{
    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT state FROM room_states WHERE room = ? FOR UPDATE");
        $stmt->execute([$room]);
        $row = $stmt->fetch();
        
        $data = $default;
        $exists = false;
        if ($row) {
            $stateStr = $row['state'];
            if (strpos($stateStr, '{') === 0) {
                $decoded = json_decode($stateStr, true);
            } else {
                $decrypted = sketch_decrypt_state($stateStr, $room);
                $decoded = $decrypted ? json_decode($decrypted, true) : null;
            }
            if (is_array($decoded)) {
                $data = $decoded;
            }
            $exists = true;
        }
        
        $result = $callback($data);
        if (!is_array($result)) {
            $result = $data;
        }
        
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
        $encrypted = sketch_encrypt_state($encoded, $room);
        if ($exists) {
            $stmt = $db->prepare("UPDATE room_states SET state = ?, updated_at = ? WHERE room = ?");
            $stmt->execute([$encrypted, time(), $room]);
        } else {
            $stmt = $db->prepare("INSERT INTO room_states (room, state, updated_at) VALUES (?, ?, ?)");
            $stmt->execute([$room, $encrypted, time()]);
        }
        
        $db->commit();
        return $result;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function sketch_room_is_valid(string $room): bool
{
    if ($room === '') {
        return false;
    }
    
    // Check if room exists in registered rooms
    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $stmt = $db->prepare("SELECT id FROM rooms WHERE code = ? LIMIT 1");
        $stmt->execute([$room]);
        if ($stmt->fetch()) {
            return true;
        }
        
        // Check if room exists in room states
        $stmt = $db->prepare("SELECT room FROM room_states WHERE room = ? LIMIT 1");
        $stmt->execute([$room]);
        if ($stmt->fetch()) {
            return true;
        }
    } catch (Exception $e) {
        // Fall back
    }
    
    // Check if room files exist
    $sigFile = sketch_room_file($room);
    $stateFile = sketch_state_file($room);
    if (is_file($sigFile) || is_file($stateFile)) {
        return true;
    }
    
    return false;
}

function sketch_filter_active_peers($peers, int $ttl): array
{
    if (!is_array($peers)) {
        return [];
    }

    $now = time();
    $active = [];

    foreach ($peers as $peer) {
        if (!is_array($peer)) {
            continue;
        }

        $ts = (int) ($peer['ts'] ?? 0);
        if ($ts > 0 && ($now - $ts) < $ttl) {
            $id = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($peer['id'] ?? ''));
            if ($id !== '') {
                if (isset($peer['cursor']) && !is_array($peer['cursor'])) {
                    $peer['cursor'] = null;
                }
                $active[$id] = $peer;
            }
        }
    }

    return $active;
}

function sketch_delete_room_files(string $room): void
{
    $room = sketch_room_id($room);
    if ($room === '') {
        return;
    }

    @unlink(sketch_room_file($room));
    @unlink(sketch_state_file($room));

    try {
        global $db;
        require_once __DIR__ . '/db.php';
        $stmt = $db->prepare("DELETE FROM room_states WHERE room = ?");
        $stmt->execute([$room]);
        $stmt = $db->prepare("DELETE FROM room_memberships WHERE room_code = ?");
        $stmt->execute([$room]);
    } catch (Exception $e) {
        // Ignore
    }
}

function sketch_room_should_be_deleted(array $roomData, string $stateFile, int $cleanupAfter): bool
{
    $room = $roomData['room'] ?? '';
    if ($room !== '') {
        try {
            global $db;
            require_once __DIR__ . '/db.php';
            $stmt = $db->prepare("SELECT id FROM rooms WHERE code = ? LIMIT 1");
            $stmt->execute([$room]);
            if ($stmt->fetch()) {
                return false; // Never auto-delete registered user rooms
            }
        } catch (Exception $e) {
            // Ignore database check issues and fall back to standard behavior
        }
    }

    $peers = sketch_filter_active_peers($roomData['peers'] ?? [], sketch_peer_ttl());
    if (!empty($peers)) {
        return false;
    }

    $lastTouched = max(
        (int) ($roomData['updated_at'] ?? 0),
        (int) ($roomData['created_at'] ?? 0),
        is_file($stateFile) ? (int) filemtime($stateFile) : 0
    );

    return $lastTouched > 0 && (time() - $lastTouched) >= $cleanupAfter;
}

function sketch_cleanup_room_if_idle(string $room, array $roomData, ?int $cleanupAfter = null): bool
{
    $gracePeriod = $cleanupAfter ?? sketch_idle_room_grace_period();
    if (!sketch_room_should_be_deleted($roomData, sketch_state_file($room), $gracePeriod)) {
        return false;
    }

    sketch_delete_room_files($room);
    return true;
}

function sketch_cleanup_stale_rooms(?int $cleanupAfter = null): void
{
    $gracePeriod = $cleanupAfter ?? sketch_idle_room_grace_period();
    $roomsDir = __DIR__ . '/rooms';
    if (!is_dir($roomsDir)) {
        return;
    }

    $files = glob($roomsDir . '/*.json');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $roomFile) {
        $room = sketch_room_id(pathinfo($roomFile, PATHINFO_FILENAME));
        if ($room === '') {
            continue;
        }

        $roomData = sketch_read_json_file($roomFile, [
            'room' => $room,
            'created_at' => 0,
            'updated_at' => 0,
            'peers' => [],
        ]);

        if (sketch_room_should_be_deleted($roomData, sketch_state_file($room), $gracePeriod)) {
            sketch_delete_room_files($room);
        }
    }
}
