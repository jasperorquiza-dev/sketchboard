<?php
// dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = sketch_base_url();
$assetBase = sketch_asset_base();

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $baseUrl . '/auth.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$error = '';
$success = '';

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $baseUrl . '/auth.php');
    exit;
}

// Handle Room Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_room') {
    if (!sketch_verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired or invalid security token. Please try again.';
    } else {
        $roomName = trim((string)($_POST['room_name'] ?? ''));
        if ($roomName === '') {
            $error = 'Please enter a name for the whiteboard.';
        } else {
        try {
            // Generate unique room code
            $isUnique = false;
            $roomCode = '';
            while (!$isUnique) {
                $roomCode = 'sk-' . substr(bin2hex(random_bytes(8)), 0, 10);
                $stmt = $db->prepare("SELECT id FROM rooms WHERE code = ? LIMIT 1");
                $stmt->execute([$roomCode]);
                if (!$stmt->fetch()) {
                    $isUnique = true;
                }
            }

            // Insert room into database
            $stmt = $db->prepare("INSERT INTO rooms (code, name, owner_user_id, created_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$roomCode, $roomName, $userId, time()]);

            // Initialize room JSON files
            $roomFile = sketch_room_file($roomCode);
            sketch_with_locked_json_file($roomFile, [
                'room' => $roomCode,
                'created_at' => time(),
                'updated_at' => time(),
                'peers' => [],
            ], static function (array $room): array {
                $room['created_at'] = $room['created_at'] ?? time();
                $room['updated_at'] = time();
                $room['peers'] = [];
                return $room;
            });

            // Redirect to the newly created room
            header('Location: ' . $baseUrl . '/v.php?id=' . rawurlencode($roomCode) . '&intro=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
        }
    }
}

// Handle Room Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_room') {
    if (!sketch_verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired or invalid security token. Please try again.';
    } else {
        $roomCode = trim((string)($_POST['room_code'] ?? ''));
        if ($roomCode !== '') {
        try {
            // Verify ownership before deleting
            $stmt = $db->prepare("SELECT id FROM rooms WHERE code = ? AND owner_user_id = ? LIMIT 1");
            $stmt->execute([$roomCode, $userId]);
            if ($stmt->fetch()) {
                // Delete from DB
                $stmt = $db->prepare("DELETE FROM rooms WHERE code = ? AND owner_user_id = ?");
                $stmt->execute([$roomCode, $userId]);
                
                // Delete physical JSON files
                sketch_delete_room_files($roomCode);
                $success = 'Whiteboard room deleted successfully.';
            } else {
                $error = 'You do not own this whiteboard room.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
        }
    }
}

// Fetch user's rooms
$userRooms = [];
try {
    $stmt = $db->prepare("SELECT * FROM rooms WHERE owner_user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $userRooms = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Database error fetching rooms: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Sketchboard Dashboard</title>

    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/fonts/fonts.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/style.css?v=<?php echo (int)filemtime(__DIR__ . '/style.css'); ?>">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/favicon.svg">

    <style>
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            background-color: var(--clr-paper);
            background-image: 
                linear-gradient(var(--clr-rule-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--clr-rule-line) 1px, transparent 1px);
            background-size: 24px 24px;
            overflow-y: auto;
            padding: 40px 20px;
        }

        .dashboard-container {
            width: min(95vw, 900px);
            display: flex;
            flex-direction: column;
            gap: 30px;
            position: relative;
        }

        .dashboard-header {
            background: var(--clr-paper);
            border: var(--border-width) solid var(--border-color);
            box-shadow: var(--shadow-paper);
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            transform: rotate(-0.3deg);
            position: relative;
            border-radius: 6px;
        }

        .back-hub {
            background: var(--clr-yellow);
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            padding: 6px 16px;
            text-decoration: none;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--clr-ink);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.15s ease;
        }

        .back-hub:hover {
            background: var(--clr-yellow-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }

        .dashboard-title-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .dashboard-title h1 {
            font-family: var(--font-header);
            font-size: clamp(2.2rem, 6vw, 3rem);
            margin: 0;
            color: #8d6e63; /* Warm brown stamp ink */
            font-weight: 700;
            letter-spacing: 1px;
            line-height: 1;
            transform: rotate(-1.5deg);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-name {
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--clr-ink);
            background: var(--clr-yellow);
            padding: 6px 14px;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }

        .logout-btn {
            background: var(--clr-red);
            color: var(--clr-ink);
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            padding: 6px 14px;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .logout-btn:hover {
            background: var(--clr-red-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }

        .logout-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        @media (min-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 320px 1fr;
            }
        }

        .card {
            background: var(--clr-paper);
            border: var(--border-width) solid var(--border-color);
            box-shadow: var(--shadow-stacked);
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            transition: transform 0.25s ease;
            border-radius: 6px;
        }

        .card.sidebar-card {
            transform: rotate(-0.8deg);
        }

        .card.main-card {
            transform: rotate(0.5deg);
        }

        .card.sidebar-card:hover,
        .card.main-card:hover {
            transform: rotate(0deg);
        }

        .card-title {
            font-family: var(--font-header);
            font-size: 1.8rem;
            margin: 0;
            color: var(--clr-ink);
            border-bottom: 1px solid rgba(0,0,0,0.12);
            padding-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .input-group label {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: var(--clr-ink-light);
            letter-spacing: 0.5px;
        }

        .dash-input {
            padding: 10px 14px;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            font-family: var(--font-body);
            font-size: 0.95rem;
            width: 100%;
            background: #ffffff;
            text-align: center;
            transition: all 0.15s ease;
        }

        .dash-input:focus {
            border-color: #8d6e63;
            box-shadow: 0 0 0 3px rgba(141, 110, 99, 0.15);
        }

        .dash-btn {
            background: var(--clr-green);
            color: var(--clr-ink);
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            cursor: pointer;
            font-family: var(--font-body);
            width: 100%;
            transition: all 0.15s ease;
        }

        .dash-btn:hover {
            background: var(--clr-green-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.08);
        }

        .dash-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .alert {
            padding: 12px;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            text-align: center;
        }

        .alert.error {
            background: var(--clr-red);
            color: var(--clr-ink);
            border-color: rgba(239,83,80,0.25);
        }

        .alert.success {
            background: var(--clr-green);
            color: var(--clr-ink);
            border-color: rgba(102,187,106,0.25);
        }

        .rooms-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .room-item {
            background: var(--clr-paper);
            border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border-radius: 5px;
            transition: all 0.15s ease;
        }

        .room-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
            border-color: rgba(0,0,0,0.15);
        }

        .room-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .room-name-text {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--clr-ink);
        }

        .room-meta-text {
            font-size: 0.75rem;
            color: var(--clr-ink-light);
            font-family: var(--font-typewriter);
        }

        .room-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 12px;
            font-family: var(--font-body);
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.15);
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            text-decoration: none;
            color: var(--clr-ink);
            transition: all 0.15s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0,0,0,0.08);
        }

        .action-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .btn-join {
            background: var(--clr-purple);
            color: var(--clr-ink);
        }
        .btn-join:hover {
            background: var(--clr-purple-hover);
        }

        .btn-copy {
            background: var(--clr-yellow);
        }
        .btn-copy:hover {
            background: var(--clr-yellow-hover);
        }

        .btn-delete {
            background: var(--clr-red);
            color: var(--clr-ink);
        }
        .btn-delete:hover {
            background: var(--clr-red-hover);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            font-weight: 600;
            color: var(--clr-ink-light);
            border: 1px dashed rgba(0,0,0,0.25);
            border-radius: 5px;
            background: rgba(0,0,0,0.02);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <header class="dashboard-header">
            <div class="dashboard-title-area">
                <div class="dashboard-title">
                    <h1>SKETCHBOARD</h1>
                </div>
            </div>
            <div class="user-badge">
                <span class="user-name">HELLO, <?php echo htmlspecialchars(strtoupper($username), ENT_QUOTES, 'UTF-8'); ?>!</span>
                <a href="dashboard.php?action=logout" class="logout-btn">LOGOUT</a>
            </div>
        </header>

        <!-- Alerts -->
        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Sidebar: Create & Join Room -->
            <aside class="card sidebar-card" style="gap:25px;">
                <div>
                    <h2 class="card-title" style="margin-bottom:15px;">NEW BOARD</h2>
                    <form method="POST" action="dashboard.php" style="display:flex; flex-direction:column; gap:15px;">
                        <input type="hidden" name="action" value="create_room">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <div class="input-group">
                            <label for="room-name">Board Name</label>
                            <input type="text" id="room-name" name="room_name" class="dash-input" placeholder="e.g. Brainstorm Session" required maxlength="40">
                        </div>

                        <button type="submit" class="dash-btn">CREATE BOARD &rarr;</button>
                    </form>
                </div>

                <div style="border-top: 1px dashed rgba(0,0,0,0.15); padding-top:20px;">
                    <h2 class="card-title" style="margin-bottom:15px;">JOIN BOARD</h2>
                    <form id="join-code-form" onsubmit="event.preventDefault(); joinWithCode();" style="display:flex; flex-direction:column; gap:15px;">
                        <div class="input-group">
                            <label for="room-code">Board Code</label>
                            <input type="text" id="room-code" class="dash-input" placeholder="e.g. c3efd6591e" required>
                        </div>

                        <button type="submit" class="dash-btn" style="background:var(--clr-purple);">JOIN BOARD &rarr;</button>
                    </form>
                </div>
            </aside>

            <!-- Main Panel: Rooms List -->
            <main class="card main-card">
                <h2 class="card-title">MY WHITEBOARDS</h2>
                
                <?php if (empty($userRooms)): ?>
                    <div class="empty-state">
                        You haven't created any whiteboards yet. Create one on the left to start sketching!
                    </div>
                <?php else: ?>
                    <div class="rooms-list">
                        <?php foreach ($userRooms as $room): ?>
                            <div class="room-item">
                                <div class="room-details">
                                    <span class="room-name-text"><?php echo htmlspecialchars($room['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="room-meta-text">
                                        CODE: <?php echo htmlspecialchars(substr($room['code'], 3), ENT_QUOTES, 'UTF-8'); ?> &bull; 
                                        CREATED: <?php echo date('Y-m-d H:i', $room['created_at']); ?>
                                    </span>
                                </div>
                                <div class="room-actions">
                                    <a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/v.php?id=<?php echo rawurlencode($room['code']); ?>" class="action-btn btn-join">JOIN</a>
                                    <button class="action-btn btn-copy" onclick="copyRoomLink('<?php echo htmlspecialchars(substr($room['code'], 3), ENT_QUOTES, 'UTF-8'); ?>')">COPY</button>
                                    
                                    <form method="POST" action="dashboard.php" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_room">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sketch_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="room_code" value="<?php echo htmlspecialchars($room['code'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="action-btn btn-delete" onclick="confirmDelete(this.form)">DELETE</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Custom Dialog Overlay -->
    <div id="custom-dialog-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:99999; align-items:center; justify-content:center; opacity:0; transition:opacity 0.2s ease;">
        <div id="custom-dialog-box" class="card" style="width:min(90vw, 380px); transform:scale(0.9) rotate(-1deg); transition:transform 0.2s ease; padding: 25px; gap: 15px; background:var(--clr-paper); text-align:center;">
            <h3 id="custom-dialog-title" style="margin:0; font-family:var(--font-header); font-size:1.8rem; border:none; padding:0;">ALERT</h3>
            <p id="custom-dialog-message" style="margin:0; font-size:0.95rem; line-height:1.4; color:var(--clr-ink-light);"></p>
            <div id="custom-dialog-actions" style="display:flex; gap:10px; justify-content:center; margin-top:10px;">
                <!-- Action buttons -->
            </div>
        </div>
    </div>

    <script>
        function copyRoomLink(code) {
            navigator.clipboard.writeText(code).then(() => {
                customAlert('Board code copied: ' + code);
            }).catch(err => {
                console.error('Failed to copy code: ', err);
            });
        }

        function joinWithCode() {
            const codeInput = document.getElementById('room-code').value.trim();
            if (!codeInput) return;
            let code = codeInput;
            if (!code.startsWith('sk-')) {
                code = 'sk-' + code;
            }
            const baseUrl = '<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>';
            window.location.href = baseUrl + '/v.php?id=' + encodeURIComponent(code);
        }

        function showCustomDialog({ title, message, actions }) {
            const overlay = document.getElementById('custom-dialog-overlay');
            const box = document.getElementById('custom-dialog-box');
            const titleEl = document.getElementById('custom-dialog-title');
            const messageEl = document.getElementById('custom-dialog-message');
            const actionsEl = document.getElementById('custom-dialog-actions');

            titleEl.textContent = title;
            messageEl.textContent = message;
            actionsEl.innerHTML = '';

            actions.forEach(action => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dash-btn';
                btn.style.padding = '8px 16px';
                btn.style.fontSize = '0.9rem';
                btn.style.width = 'auto';
                btn.style.marginTop = '0';
                if (action.style) {
                    Object.assign(btn.style, action.style);
                }
                btn.textContent = action.label;
                btn.onclick = () => {
                    closeCustomDialog();
                    if (action.onClick) action.onClick();
                };
                actionsEl.appendChild(btn);
            });

            overlay.style.display = 'flex';
            void overlay.offsetWidth; // Trigger layout reflow
            overlay.style.opacity = '1';
            box.style.transform = 'scale(1) rotate(-0.5deg)';
        }

        function closeCustomDialog() {
            const overlay = document.getElementById('custom-dialog-overlay');
            const box = document.getElementById('custom-dialog-box');
            overlay.style.opacity = '0';
            box.style.transform = 'scale(0.9) rotate(-1.5deg)';
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 200);
        }

        function customAlert(message) {
            showCustomDialog({
                title: 'NOTE',
                message: message,
                actions: [
                    { label: 'GOT IT', style: { background: 'var(--clr-yellow)' } }
                ]
            });
        }

        function confirmDelete(form) {
            showCustomDialog({
                title: 'DELETE BOARD?',
                message: 'Are you sure you want to delete this whiteboard? This cannot be undone.',
                actions: [
                    { 
                        label: 'CANCEL', 
                        style: { background: 'rgba(0,0,0,0.06)' } 
                    },
                    { 
                        label: 'DELETE', 
                        style: { background: 'var(--clr-red)' },
                        onClick: () => form.submit()
                    }
                ]
            });
        }
    </script>
</body>
</html>
