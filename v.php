<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$baseUrl = sketch_base_url();
$assetBase = sketch_asset_base();

// Enforce login
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $baseUrl . '/auth.php');
    exit;
}

sketch_cleanup_stale_rooms();

$roomId = sketch_room_id($_GET['id'] ?? null);
$isExpired = !sketch_room_is_valid($roomId);

if (!$isExpired) {
    sketch_record_room_membership((int) ($_SESSION['user_id'] ?? 0), $roomId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Sketch - Collaborative Whiteboard</title>

    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/fonts/fonts.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/lib/nouislider.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/style.css?v=<?php echo (int) filemtime(__DIR__ . '/style.css'); ?>">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/favicon.svg">

    <!-- Stylesheets and visual assets loaded here -->
</head>
<body>
    <div id="page-loader">
        <div class="loader-spinner"></div>
    </div>

    <div class="app-container">
        <header class="top-bar" id="main-header">
            <div class="bar-left">
                <a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/" class="home-btn" title="Back to Dashboard">DASHBOARD</a>
                <div class="room-info">
                    <span id="room-id-display"><?php echo htmlspecialchars($roomId !== '' ? $roomId : 'invalid-room', ENT_QUOTES, 'UTF-8'); ?></span>
                    <button id="copy-link-btn" class="mini-btn" type="button">COPY</button>
                </div>
            </div>

            <div class="bar-right">
                <button id="chat-btn" class="mini-btn" type="button" title="Open Chat">
                    CHAT
                    <span id="chat-dot" class="notification-dot hidden"></span>
                </button>
                <div class="connection-pill" title="View Members">
                    <div id="connection-status" class="status-dot"></div>
                    <div id="peer-count">1</div>
                </div>
            </div>
        </header>

        <div class="tool-bar" id="main-tools">
            <div class="control-group">
                <button id="undo-btn" type="button" title="Undo (Ctrl+Z)"><i class="ph ph-arrow-u-up-left"></i></button>
                <button id="redo-btn" type="button" title="Redo (Ctrl+Y)"><i class="ph ph-arrow-u-up-right"></i></button>
            </div>

            <div class="control-group">
                <button id="mode-toggle" type="button" title="Movement Mode (2)"><i class="ph ph-hand-grabbing"></i></button>
                <button id="draw-mode-btn" type="button" title="Draw Mode (1)" class="active"><i class="ph ph-pencil-simple"></i></button>
                <button id="eraser-btn" type="button" title="Eraser Tool (E)"><i class="ph ph-eraser"></i></button>
                <div class="color-picker-wrap">
                    <input type="color" id="color-picker" value="#111111" title="Brush Color" hidden>
                    <button id="color-picker-trigger" type="button" title="Brush Color" aria-label="Brush Color">
                        <span id="color-swatch"></span>
                    </button>
                </div>

                <div class="brush-settings" id="brush-panel">
                    <button id="size-toggle" type="button" title="Custom Size"><i class="ph ph-sliders"></i></button>
                    <div class="size-preview-wrap">
                        <div id="size-preview"></div>
                        <span id="size-preview-value">4</span>
                    </div>
                </div>
            </div>

            <div class="control-group">
                <button id="clear-btn" class="danger" type="button" title="Clear Board"><i class="ph ph-trash"></i></button>
                <button id="export-btn" type="button" title="Export Image"><i class="ph ph-download-simple"></i></button>
            </div>

            <div class="control-group view-controls">
                <button id="zoom-in" type="button" title="Zoom In"><i class="ph ph-magnifying-glass-plus"></i></button>
                <button id="zoom-out" type="button" title="Zoom Out"><i class="ph ph-magnifying-glass-minus"></i></button>
                <button id="fit-view" type="button" title="Fit to Drawing (F)" class="mini-btn">FIT</button>
            </div>
        </div>

        <main class="canvas-wrapper">
            <canvas id="whiteboard"></canvas>
            <div id="cursors-container"></div>
        </main>

        <div class="chat-modal hidden" id="chat-widget">
            <div class="chat-header">
                <span>CHAT ROOM</span>
                <button id="close-chat" class="mini-btn" type="button">&times;</button>
            </div>
            <div class="chat-body">
                <div id="chat-messages"></div>
                <form id="chat-form">
                    <input type="text" id="chat-input" placeholder="Say something..." autocomplete="off" maxlength="400">
                    <button type="submit">SEND</button>
                </form>
            </div>
        </div>
    </div>

    <div id="color-picker-popover" class="color-picker-sheet" aria-hidden="true">
        <div class="color-picker-sheet-inner">
            <div class="color-picker-sheet-title">Brush Color</div>
            <div id="color-picker-widget"></div>
        </div>
    </div>

    <div id="size-picker-popover" class="tool-sheet" aria-hidden="true">
        <div class="tool-sheet-inner">
            <div class="size-picker-controls">
                <div id="size-slider" aria-label="Brush size"></div>
            </div>
        </div>
    </div>

    <div id="name-modal" class="modal-overlay">
        <div class="modal">
            <h2>Enter Your Name</h2>
            <p>Join the room with a name so everyone can see who is drawing.</p>
            <input type="text" id="user-nickname" placeholder="Your Name" maxlength="20">
            <button id="join-btn" type="button">START SKETCHING</button>
        </div>
    </div>

    <div id="members-modal" class="modal-overlay hidden">
        <div class="modal">
            <h2>Active Members</h2>
            <div id="members-list" class="members-list"></div>
            <button id="close-members" type="button">BACK TO CANVAS</button>
        </div>
    </div>

    <?php if ($isExpired): ?>
    <div id="expired-modal" class="modal-overlay">
        <div class="modal" style="transform: rotate(-0.5deg);">
            <h2 style="font-size: 2.5rem; color: #ef5350; margin-bottom: 10px;">LINK EXPIRED</h2>
            <p style="margin-bottom: 25px;">This room no longer exists. Start a new sketch to create a fresh board.</p>
            <a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/" style="text-decoration:none; display:block;">
                <button type="button" class="dash-btn" style="width:100%; height:50px; background:var(--clr-purple); color:var(--clr-ink); font-size:1.2rem; cursor:pointer;">START NEW SKETCH</button>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <link rel="stylesheet" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/lib/phosphor/style.css">
    <script src="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/lib/iro.min.js"></script>
    <script>
        window.SKETCH_CONFIG = {
            roomId: <?php echo json_encode($roomId, JSON_UNESCAPED_SLASHES); ?>,
            assetBase: <?php echo json_encode($assetBase, JSON_UNESCAPED_SLASHES); ?>,
            intro: <?php echo isset($_GET['intro']) ? 'true' : 'false'; ?>,
            expired: <?php echo $isExpired ? 'true' : 'false'; ?>
        };
        // Set nickname from logged-in session automatically
        <?php if (isset($_SESSION['username']) && $_SESSION['username'] !== ''): ?>
        localStorage.setItem('sketch_nickname', <?php echo json_encode($_SESSION['username']); ?>);
        <?php endif; ?>
    </script>
    <script src="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/lib/peerjs.min.js"></script>
    <script src="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/assets/lib/nouislider.min.js"></script>
    <script>
        const hideSketchLoader = () => {
            const loader = document.getElementById('page-loader');
            if (loader) {
                loader.classList.add('hidden');
            }
        };

        window.addEventListener('pageshow', hideSketchLoader);
        window.addEventListener('pagehide', hideSketchLoader);
    </script>
    <script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/script.js?v=<?php echo (int) filemtime(__DIR__ . '/script.js'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/eraser_fix.js?v=<?php echo (int) filemtime(__DIR__ . '/eraser_fix.js'); ?>"></script>
</body>
</html>
