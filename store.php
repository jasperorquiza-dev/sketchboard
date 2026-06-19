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

$room = sketch_room_id($_GET['room'] ?? $_POST['room'] ?? null);
if ($room === '') {
    sketch_json_response(['error' => 'Invalid room.'], 422);
}

$defaultState = sketch_default_room_state($room);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = sketch_read_room_state($room, $defaultState);
    sketch_json_response([
        'ok' => true,
        'state' => $state,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sketch_json_response(['error' => 'Method not allowed.'], 405);
}

$rawBody = file_get_contents('php://input') ?: '';
if (strlen($rawBody) > 2_500_000) {
    sketch_json_response(['error' => 'Payload too large.'], 413);
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    sketch_json_response(['error' => 'Invalid JSON body.'], 400);
}

$action = (string) ($body['action'] ?? '');
if ($action === '') {
    sketch_json_response(['error' => 'Missing action.'], 422);
}

try {
    $state = sketch_with_locked_room_state($room, $defaultState, static function (array $state) use ($action, $body, $room): array {
        $state['room'] = $room;
        $state['revision'] = (int) ($state['revision'] ?? 0);
        $state['updated_at'] = (int) ($state['updated_at'] ?? time());
        $state['strokes'] = array_values(array_filter($state['strokes'] ?? [], 'is_array'));
        $state['messages'] = array_values(array_filter($state['messages'] ?? [], 'is_array'));
        $state['meta'] = is_array($state['meta'] ?? null) ? $state['meta'] : ['bounds' => null, 'stroke_count' => count($state['strokes'])];

        $changed = false;

        if ($action === 'batch') {
            $actions = $body['actions'] ?? null;
            if (!is_array($actions)) {
                throw new InvalidArgumentException('Missing batch actions.');
            }

            foreach ($actions as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $changed = sketch_apply_state_action($state, $entry, $changed);
            }
        } else {
            $changed = sketch_apply_state_action($state, $body, $changed);
        }

        if ($changed) {
            $state['revision']++;
            $state['updated_at'] = time();
        }

        $state['meta'] = sketch_build_state_meta($state['strokes']);

        return $state;
    });
} catch (InvalidArgumentException $exception) {
    sketch_json_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    sketch_json_response(['error' => 'Unable to save board state: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()], 500);
}

sketch_json_response([
    'ok' => true,
    'state' => $state,
]);

function sketch_validate_stroke($rawStroke): array
{
    if (!is_array($rawStroke)) {
        throw new InvalidArgumentException('Invalid stroke.');
    }

    $id = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($rawStroke['id'] ?? ''));
    $owner = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($rawStroke['owner'] ?? ''));
    $color = (string) ($rawStroke['color'] ?? '#111111');
    $size = (float) ($rawStroke['size'] ?? 4);
    $points = $rawStroke['points'] ?? [];

    if ($id === '' || $owner === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color) || $size < 1 || $size > 100 || !is_array($points)) {
        throw new InvalidArgumentException('Malformed stroke.');
    }

    $sanitizedPoints = [];
    foreach (array_slice($points, 0, 5000) as $point) {
        if (!is_array($point)) {
            continue;
        }

        $x = isset($point['x']) ? (float) $point['x'] : null;
        $y = isset($point['y']) ? (float) $point['y'] : null;

        if ($x === null || $y === null || !is_finite($x) || !is_finite($y)) {
            continue;
        }

        $sanitizedPoints[] = [
            'x' => max(-1000000, min(1000000, round($x, 2))),
            'y' => max(-1000000, min(1000000, round($y, 2))),
        ];
    }

    if (count($sanitizedPoints) === 0) {
        throw new InvalidArgumentException('Stroke has no points.');
    }

    return [
        'id' => $id,
        'owner' => $owner,
        'color' => strtoupper($color),
        'size' => round($size, 2),
        'points' => $sanitizedPoints,
        'createdAt' => (int) ($rawStroke['createdAt'] ?? round(microtime(true) * 1000)),
    ];
}

function sketch_apply_state_action(array &$state, array $payload, bool $changed): bool
{
    $action = (string) ($payload['action'] ?? '');
    if ($action === '') {
        throw new InvalidArgumentException('Missing action.');
    }

    if ($action === 'upsert-stroke') {
        $stroke = sketch_validate_stroke($payload['stroke'] ?? null);
        $strokes = [];
        $replaced = false;

        foreach ($state['strokes'] as $existingStroke) {
            if (($existingStroke['id'] ?? '') === $stroke['id']) {
                $strokes[] = $stroke;
                $replaced = true;
            } else {
                $strokes[] = $existingStroke;
            }
        }

        if (!$replaced) {
            $strokes[] = $stroke;
        }

        $state['strokes'] = $strokes;
        return true;
    }

    if ($action === 'remove-stroke') {
        $strokeId = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($payload['strokeId'] ?? ''));
        if ($strokeId === '') {
            throw new InvalidArgumentException('Missing stroke id.');
        }

        $before = count($state['strokes']);
        $state['strokes'] = array_values(array_filter($state['strokes'], static fn(array $stroke): bool => ($stroke['id'] ?? '') !== $strokeId));
        return $changed || count($state['strokes']) !== $before;
    }

    if ($action === 'clear') {
        $state['strokes'] = [];
        return true;
    }

    if ($action === 'add-message') {
        $message = sketch_validate_message($payload['message'] ?? null);
        $messages = [];
        $replaced = false;

        foreach ($state['messages'] as $existingMessage) {
            if (($existingMessage['id'] ?? '') === $message['id']) {
                $messages[] = $message;
                $replaced = true;
            } else {
                $messages[] = $existingMessage;
            }
        }

        if (!$replaced) {
            $messages[] = $message;
        }

        $state['messages'] = array_slice($messages, -60);
        return true;
    }

    throw new InvalidArgumentException('Unsupported action.');
}

function sketch_build_state_meta(array $strokes): array
{
    $bounds = null;

    foreach ($strokes as $stroke) {
        if (!is_array($stroke) || !is_array($stroke['points'] ?? null) || empty($stroke['points'])) {
            continue;
        }

        $size = max(1.0, (float) ($stroke['size'] ?? 1));
        foreach ($stroke['points'] as $point) {
            if (!is_array($point) || !isset($point['x'], $point['y'])) {
                continue;
            }

            $x = (float) $point['x'];
            $y = (float) $point['y'];

            if (!is_finite($x) || !is_finite($y)) {
                continue;
            }

            $left = round($x - $size, 2);
            $top = round($y - $size, 2);
            $right = round($x + $size, 2);
            $bottom = round($y + $size, 2);

            if ($bounds === null) {
                $bounds = [
                    'minX' => $left,
                    'minY' => $top,
                    'maxX' => $right,
                    'maxY' => $bottom,
                ];
                continue;
            }

            $bounds['minX'] = min($bounds['minX'], $left);
            $bounds['minY'] = min($bounds['minY'], $top);
            $bounds['maxX'] = max($bounds['maxX'], $right);
            $bounds['maxY'] = max($bounds['maxY'], $bottom);
        }
    }

    return [
        'bounds' => $bounds,
        'stroke_count' => count($strokes),
    ];
}

function sketch_validate_message($rawMessage): array
{
    if (!is_array($rawMessage)) {
        throw new InvalidArgumentException('Invalid message.');
    }

    $id = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string) ($rawMessage['id'] ?? ''));
    $name = trim((string) ($rawMessage['name'] ?? ''));
    $text = trim((string) ($rawMessage['text'] ?? ''));

    if ($id === '' || $name === '' || $text === '') {
        throw new InvalidArgumentException('Message is incomplete.');
    }

    return [
        'id' => $id,
        'name' => substr($name, 0, 20),
        'text' => substr($text, 0, 400),
        'createdAt' => (int) ($rawMessage['createdAt'] ?? round(microtime(true) * 1000)),
    ];
}
