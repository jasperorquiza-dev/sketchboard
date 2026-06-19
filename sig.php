<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Enforce Authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}
if (!isset($_SESSION['user_id'])) {
    sketch_json_response(['error' => 'Unauthorized. Please sign in.'], 401);
}

// Origin Verification for POST Requests (CSRF Mitigation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && $host !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $serverHost = parse_url('http://' . $host, PHP_URL_HOST);
        if ($originHost !== $serverHost) {
            sketch_json_response(['error' => 'Invalid origin.'], 403);
        }
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Only run stale room cleanup ~5% of requests to reduce disk I/O
if (mt_rand(1, 20) === 1) {
    sketch_cleanup_stale_rooms();
}

$room = sketch_room_id($_GET['room'] ?? $_POST['room'] ?? null);
if ($room === '') {
    sketch_json_response(['error' => 'Invalid room.'], 422);
}

$roomFile = sketch_room_file($room);
$ttl = sketch_peer_ttl();
$defaultRoom = [
    'room' => $room,
    'created_at' => time(),
    'updated_at' => time(),
    'peers' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Read without locking first to check if any peers are stale
    $rawData = sketch_read_json_file($roomFile, $defaultRoom);
    $allPeers = $rawData['peers'] ?? [];
    $activePeers = sketch_filter_active_peers($allPeers, $ttl);

    // Only acquire lock and write if stale peers were actually found
    if (count($activePeers) !== count($allPeers)) {
        $roomData = sketch_with_locked_json_file($roomFile, $defaultRoom, static function (array $roomData) use ($ttl): array {
            $roomData['peers'] = sketch_filter_active_peers($roomData['peers'] ?? [], $ttl);
            return $roomData;
        });
    } else {
        $roomData = $rawData;
        $roomData['peers'] = $activePeers;
    }

    sketch_cleanup_room_if_idle($room, $roomData);

    sketch_json_response([
        'ok' => true,
        'peers' => array_values($roomData['peers'] ?? []),
        'count' => count($roomData['peers'] ?? []),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sketch_json_response(['error' => 'Method not allowed.'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    sketch_json_response(['error' => 'Invalid JSON body.'], 400);
}

$action = (string) ($body['action'] ?? '');
$peerId = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($body['id'] ?? ''));

if ($action === '' || $peerId === '') {
    sketch_json_response(['error' => 'Missing peer action or id.'], 422);
}

$peerName = trim((string) ($body['name'] ?? ''));
$peerColor = strtoupper((string) ($body['color'] ?? '#7D5FFF'));

try {
    $cursor = sketch_normalize_cursor($body['cursor'] ?? null);
    $roomData = sketch_with_locked_json_file($roomFile, $defaultRoom, static function (array $roomData) use ($action, $peerId, $peerName, $peerColor, $cursor, $ttl): array {
        $roomData['created_at'] = (int) ($roomData['created_at'] ?? time());
        $roomData['peers'] = sketch_filter_active_peers($roomData['peers'] ?? [], $ttl);

        if ($action === 'leave') {
            unset($roomData['peers'][$peerId]);
        } else {
            $existingPeer = is_array($roomData['peers'][$peerId] ?? null) ? $roomData['peers'][$peerId] : [];
            $roomData['peers'][$peerId] = [
                'id' => $peerId,
                'name' => substr($peerName !== '' ? $peerName : 'Guest', 0, 20),
                'color' => preg_match('/^#[0-9A-F]{6}$/', $peerColor) ? $peerColor : '#7D5FFF',
                'ts' => time(),
                'cursor' => $cursor ?? ($existingPeer['cursor'] ?? null),
            ];
        }

        $roomData['updated_at'] = time();
        return $roomData;
    });
} catch (Throwable $exception) {
    sketch_json_response(['error' => 'Unable to update presence.'], 500);
}

sketch_cleanup_room_if_idle($room, $roomData);

sketch_json_response([
    'ok' => true,
    'peers' => array_values($roomData['peers'] ?? []),
    'count' => count($roomData['peers'] ?? []),
]);

function sketch_normalize_cursor($cursor): ?array
{
    if (!is_array($cursor)) {
        return null;
    }

    if (!isset($cursor['x'], $cursor['y'])) {
        return null;
    }

    $x = (float) $cursor['x'];
    $y = (float) $cursor['y'];

    if (!is_finite($x) || !is_finite($y)) {
        return null;
    }

    return [
        'x' => max(-1000000, min(1000000, round($x, 2))),
        'y' => max(-1000000, min(1000000, round($y, 2))),
        'ts' => time(),
    ];
}
