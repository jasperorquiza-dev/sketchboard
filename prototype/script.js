// script.js - Sketchpad Front-end Prototype Logic

// ================= STATE MANAGEMENT =================
let currentUser = null;
let activeRoomCode = null;
let activeTool = 'pen'; // 'pen' or 'eraser'
let activeColor = '#1a1a1a';
let brushSize = 5;
let isDrawing = false;
let lastX = 0;
let lastY = 0;

// Local mock list of rooms (persisted in localStorage for convenience)
let mockRooms = JSON.parse(localStorage.getItem('sketch_mock_rooms')) || [
    { code: 'XJ89A1', name: 'UI Planning', createdBy: 'Alice', date: '2026-06-19' },
    { code: 'KL44P2', name: 'Flowchart draft', createdBy: 'Jasper', date: '2026-06-18' }
];

// Mock Users database for mock login/register
let mockUsers = JSON.parse(localStorage.getItem('sketch_mock_users')) || {};

// Canvas Elements
let canvas = null;
let ctx = null;
let remoteCursorsContainer = null;

// ================= VIEW NAVIGATION =================
function showView(viewId) {
    document.querySelectorAll('.view').forEach(view => {
        view.classList.add('hidden');
    });
    document.getElementById(viewId).classList.remove('hidden');

    if (viewId === 'whiteboard-view') {
        initCanvas();
        startMockCollaboration();
    } else {
        stopMockCollaboration();
    }
}

// ================= AUTHENTICATION LOGIC =================
function switchAuthTab(tab) {
    const loginTab = document.getElementById('tab-login');
    const registerTab = document.getElementById('tab-register');
    const slider = document.querySelector('.auth-forms-slider');
    const loginContainer = document.getElementById('login-form-container');
    const registerContainer = document.getElementById('register-form-container');

    if (tab === 'login') {
        loginTab.classList.add('active');
        registerTab.classList.remove('active');
        slider.style.transform = 'translateX(0%)';
        loginContainer.classList.add('active');
        registerContainer.classList.add('active'); // active for slide
        setTimeout(() => registerContainer.classList.remove('active'), 400);
    } else {
        registerTab.classList.add('active');
        loginTab.classList.remove('active');
        slider.style.transform = 'translateX(-50%)';
        registerContainer.classList.add('active');
        loginContainer.classList.add('active'); // active for slide
        setTimeout(() => loginContainer.classList.remove('active'), 400);
    }
}

// Live Password Checklist validation
function checkPasswordStrength() {
    const password = document.getElementById('reg-password').value;
    
    const lengthValid = password.length >= 8;
    const upperValid = /[A-Z]/.test(password);
    const lowerValid = /[a-z]/.test(password);
    const numberValid = /[0-9]/.test(password);
    const specialValid = /[^A-Za-z0-9]/.test(password);

    updateRuleState('rule-length', lengthValid);
    updateRuleState('rule-upper', upperValid);
    updateRuleState('rule-lower', lowerValid);
    updateRuleState('rule-number', numberValid);
    updateRuleState('rule-special', specialValid);

    validateRegistrationForm();
}

function updateRuleState(elementId, isValid) {
    const el = document.getElementById(elementId);
    if (isValid) {
        el.classList.remove('invalid');
        el.classList.add('valid');
    } else {
        el.classList.remove('valid');
        el.classList.add('invalid');
    }
}

function checkPasswordMatch() {
    const password = document.getElementById('reg-password').value;
    const confirm = document.getElementById('reg-confirm').value;
    const confirmMsg = document.getElementById('confirm-msg');

    if (confirm === '') {
        confirmMsg.textContent = '';
        confirmMsg.className = 'validation-message';
    } else if (password === confirm) {
        confirmMsg.textContent = 'Passwords match!';
        confirmMsg.className = 'validation-message success';
    } else {
        confirmMsg.textContent = 'Passwords do not match.';
        confirmMsg.className = 'validation-message error';
    }
    validateRegistrationForm();
}

function validateUsername() {
    validateRegistrationForm();
}

function validateRegistrationForm() {
    const username = document.getElementById('reg-username').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const password = document.getElementById('reg-password').value;
    const confirm = document.getElementById('reg-confirm').value;
    
    const isPasswordValid = 
        password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[a-z]/.test(password) &&
        /[0-9]/.test(password) &&
        /[^A-Za-z0-9]/.test(password);

    const isMatch = (password === confirm);
    const isUserFilled = username.length >= 3;
    const isEmailFilled = email.includes('@');

    const submitBtn = document.getElementById('register-submit-btn');
    submitBtn.disabled = !(isPasswordValid && isMatch && isUserFilled && isEmailFilled);
}

// Mock Register action
function handleMockRegister(event) {
    event.preventDefault();
    const username = document.getElementById('reg-username').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const password = document.getElementById('reg-password').value;

    /* 
      PHASE 2 WORK NOTE: 
      Here, you would make an AJAX/Fetch request to a backend endpoint (e.g. POST /api/register) 
      which will securely validate inputs, hash the password using BCRYPT, and store the user in a database.
    */

    if (mockUsers[username.toLowerCase()]) {
        showAlert("Username already taken. Please choose another!");
        return;
    }

    mockUsers[username.toLowerCase()] = { username, email, password };
    localStorage.setItem('sketch_mock_users', JSON.stringify(mockUsers));

    showAlert("Account created. Please sign in first.");
    
    // Clear registration fields
    document.getElementById('register-form').reset();
    checkPasswordStrength();
    checkPasswordMatch();

    // Switch back to Login Tab
    switchAuthTab('login');
}

// Mock Login action
function handleMockLogin(event) {
    event.preventDefault();
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;

    /*
      PHASE 2 WORK NOTE:
      Here, you would send a POST request /api/login to authenticate session/token creation.
      The server verifies credentials, creates session cookies or a JWT, and returns authenticated user info.
    */

    const userObj = mockUsers[username.toLowerCase()];
    if (userObj && userObj.password === password) {
        currentUser = userObj.username;
    } else {
        // Allow a quick fallback for testing without registering first
        currentUser = username; 
    }

    // Set Welcome Display
    document.getElementById('user-display-name').textContent = currentUser;
    
    // Load Dashboard
    renderRoomsList();
    showView('dashboard-view');
}

function handleMockLogout() {
    currentUser = null;
    document.getElementById('login-form').reset();
    showView('auth-view');
}

// ================= DASHBOARD ROOM LOGIC =================
function renderRoomsList() {
    const listContainer = document.getElementById('rooms-list-container');
    listContainer.innerHTML = '';

    if (mockRooms.length === 0) {
        listContainer.innerHTML = `
            <div class="empty-state">
                No recent sketchpads found. Create one above to get drawing!
            </div>
        `;
        return;
    }

    // Rotate styles to keep notebook feel
    const rotations = ['rot-left', 'rot-right', 'rot-slight', ''];

    mockRooms.forEach((room, index) => {
        const rotationClass = rotations[index % rotations.length];
        const note = document.createElement('div');
        note.className = `sticky-note ${rotationClass}`;
        note.onclick = () => selectRoom(room.code);
        
        note.innerHTML = `
            <div>
                <span class="sticky-code">#${room.code}</span>
                <div class="sticky-title">${room.name}</div>
            </div>
            <div class="sticky-meta">
                <span>By: ${room.createdBy}</span>
                <span>${room.date}</span>
            </div>
        `;
        listContainer.appendChild(note);
    });
}

function createRoom() {
    const characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Easy read characters (no I, O, 0, 1)
    let newCode = '';
    for (let i = 0; i < 6; i++) {
        newCode += characters.charAt(Math.floor(Math.random() * characters.length));
    }

    const roomName = prompt("Enter a name for your sketchpad:", `Board #${newCode}`);
    if (roomName === null) return; // cancelled
    
    const finalName = roomName.trim() === '' ? `Sketchpad #${newCode}` : roomName.trim();

    /*
      PHASE 2 WORK NOTE:
      Call backend to reserve this code in the database:
      POST /api/rooms { code: newCode, name: finalName }
    */

    const newRoom = {
        code: newCode,
        name: finalName,
        createdBy: currentUser || 'Guest',
        date: new Date().toISOString().split('T')[0]
    };

    mockRooms.unshift(newRoom);
    localStorage.setItem('sketch_mock_rooms', JSON.stringify(mockRooms));
    
    selectRoom(newCode);
}

function joinRoom() {
    const codeInput = document.getElementById('join-room-code');
    const code = codeInput.value.trim().toUpperCase();

    if (code.length !== 6) {
        showAlert("Room code must be exactly 6 characters.");
        return;
    }

    /*
      PHASE 2 WORK NOTE:
      Query database to verify room code existence:
      GET /api/rooms/verify?code=XYZABC
    */

    // Add to list if not already there (mock feature)
    const existing = mockRooms.find(r => r.code === code);
    if (!existing) {
        mockRooms.unshift({
            code: code,
            name: `Joined Board #${code}`,
            createdBy: 'External',
            date: new Date().toISOString().split('T')[0]
        });
        localStorage.setItem('sketch_mock_rooms', JSON.stringify(mockRooms));
    }

    codeInput.value = '';
    selectRoom(code);
}

function selectRoom(code) {
    activeRoomCode = code;
    document.getElementById('active-room-code').textContent = `#${code}`;
    showView('whiteboard-view');
}

function exitRoom() {
    activeRoomCode = null;
    renderRoomsList();
    showView('dashboard-view');
}

// ================= WHITEBOARD CANVAS DRAWING =================
function initCanvas() {
    canvas = document.getElementById('sketch-canvas');
    ctx = canvas.getContext('2d');
    remoteCursorsContainer = document.getElementById('remote-cursors');

    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
    
    // Smooth rendering parameters
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    updateDrawingStyle();

    // Event Listeners for drawing (ensure no double-binding)
    canvas.removeEventListener('mousedown', startDrawing);
    canvas.removeEventListener('mousemove', draw);
    canvas.removeEventListener('mouseup', stopDrawing);
    canvas.removeEventListener('mouseout', stopDrawing);
    
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);

    // Touch Event Listeners for mobile devices
    canvas.removeEventListener('touchstart', handleTouchStart);
    canvas.removeEventListener('touchmove', handleTouchMove);
    canvas.removeEventListener('touchend', stopDrawing);

    canvas.addEventListener('touchstart', handleTouchStart, { passive: false });
    canvas.addEventListener('touchmove', handleTouchMove, { passive: false });
    canvas.addEventListener('touchend', stopDrawing);

    // Dynamic resize handler
    window.removeEventListener('resize', resizeCanvas);
    window.addEventListener('resize', resizeCanvas);
}

function resizeCanvas() {
    if (!canvas || canvas.offsetParent === null) return;
    
    // Save current canvas contents
    const tempCanvas = document.createElement('canvas');
    const tempCtx = tempCanvas.getContext('2d');
    tempCanvas.width = canvas.width;
    tempCanvas.height = canvas.height;
    tempCtx.drawImage(canvas, 0, 0);

    // Resize
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;

    // Restore contents
    ctx.drawImage(tempCanvas, 0, 0);

    // Restore line parameters
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    updateDrawingStyle();
}

function updateDrawingStyle() {
    if (!ctx) return;
    if (activeTool === 'eraser') {
        ctx.strokeStyle = '#FAF6EE'; // Matches the Moleskine background color
        ctx.lineWidth = brushSize * 2.5; // Eraser size helper
    } else {
        ctx.strokeStyle = activeColor;
        ctx.lineWidth = brushSize;
    }
}

function selectTool(tool) {
    activeTool = tool;
    document.getElementById('tool-pen').classList.toggle('active', tool === 'pen');
    document.getElementById('tool-eraser').classList.toggle('active', tool === 'eraser');
    updateDrawingStyle();
}

function selectColor(color, element) {
    activeColor = color;
    document.querySelectorAll('.color-swatch').forEach(swatch => {
        swatch.classList.remove('active');
    });
    if (element) {
        element.classList.add('active');
    }
    document.getElementById('canvas-color-picker').value = color;
    selectTool('pen');
    updateDrawingStyle();
}

function handleCustomColor(color) {
    activeColor = color;
    document.querySelectorAll('.color-swatch').forEach(swatch => {
        swatch.classList.remove('active'); // Deactivate default presets
    });
    selectTool('pen');
    updateDrawingStyle();
}

function updateBrushSize(size) {
    brushSize = size;
    document.getElementById('brush-size-val').textContent = `${size}px`;
    updateDrawingStyle();
}

function clearCanvas() {
    if (confirm("Are you sure you want to clear your sketchpad?")) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        /* 
          PHASE 2 WORK NOTE: 
          For real-time sync, emit a websocket event: 
          socket.emit('clear-canvas', { room: activeRoomCode });
        */
    }
}

// Drawing operations
function startDrawing(e) {
    isDrawing = true;
    const coords = getEventCoords(e);
    lastX = coords.x;
    lastY = coords.y;
}

function draw(e) {
    if (!isDrawing) return;
    const coords = getEventCoords(e);
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(coords.x, coords.y);
    ctx.stroke();

    /*
      PHASE 2 WORK NOTE:
      Send stroke packets to backend via WebSocket:
      socket.emit('draw-stroke', {
          room: activeRoomCode,
          x0: lastX / canvas.width, // normalized ratio
          y0: lastY / canvas.height,
          x1: coords.x / canvas.width,
          y1: coords.y / canvas.height,
          color: activeColor,
          size: brushSize,
          isEraser: activeTool === 'eraser'
      });
    */

    lastX = coords.x;
    lastY = coords.y;
}

function stopDrawing() {
    isDrawing = false;
}

// Coords normalizer
function getEventCoords(e) {
    const rect = canvas.getBoundingClientRect();
    // Support Touch and Mouse events
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return {
        x: clientX - rect.left,
        y: clientY - rect.top
    };
}

// Touch event wrappers
function handleTouchStart(e) {
    e.preventDefault();
    startDrawing(e);
}

// Touch move wrapper
function handleTouchMove(e) {
    e.preventDefault();
    draw(e);
}

// ================= MOCK COLLABORATION SYNC =================
let mockCollaborationInterval = null;
let mockUsersList = ['Leo', 'Sarah', 'Alex', 'Grace', 'Kai'];

function startMockCollaboration() {
    const activeUsersList = document.getElementById('active-users-list');
    activeUsersList.innerHTML = '';
    
    // Add Self
    const selfName = currentUser || 'Guest';
    const selfInitials = selfName.slice(0, 2).toUpperCase();
    activeUsersList.innerHTML += `<div class="avatar" data-name="${selfName} (You)" style="background-color: #ffd4e5;">${selfInitials}</div>`;

    // Pick 2 random users to sit in the room
    const shuffled = [...mockUsersList].sort(() => 0.5 - Math.random());
    const attendees = shuffled.slice(0, 2);
    const colors = ['#fff9ae', '#d4f0ff', '#e8fcd4'];

    attendees.forEach((user, i) => {
        const initials = user.slice(0, 2).toUpperCase();
        activeUsersList.innerHTML += `<div class="avatar" data-name="${user}" style="background-color: ${colors[i % colors.length]};">${initials}</div>`;
    });

    // Spawn mock remote cursors moving dynamically
    remoteCursorsContainer.innerHTML = '';
    attendees.forEach((user, i) => {
        const cursor = document.createElement('div');
        cursor.id = `mock-cursor-${i}`;
        cursor.className = 'mock-cursor';
        cursor.innerHTML = `<span class="cursor-label">${user}</span>`;
        remoteCursorsContainer.appendChild(cursor);
    });

    // Animate mock remote cursors
    mockCollaborationInterval = setInterval(() => {
        attendees.forEach((user, i) => {
            const cursor = document.getElementById(`mock-cursor-${i}`);
            if (!cursor) return;

            // Generate next random coordinate within canvas boundaries
            const targetX = Math.random() * (canvas.width - 80) + 10;
            const targetY = Math.random() * (canvas.height - 40) + 10;

            cursor.style.left = `${targetX}px`;
            cursor.style.top = `${targetY}px`;

            // Draw rare mock lines to show simulated active drawing
            if (Math.random() > 0.82) {
                ctx.strokeStyle = colors[i % colors.length];
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.arc(targetX, targetY, Math.random() * 8 + 2, 0, Math.PI * 2);
                ctx.stroke();
                updateDrawingStyle(); // Restore personal settings
            }
        });
    }, 2000);

    /*
      PHASE 2 WORK NOTE:
      In Phase 2, connect to Socket.io/WebSockets:
      socket.on('mouse-move', (data) => {
          updateRemoteCursor(data.user, data.x * canvas.width, data.y * canvas.height);
      });
      socket.on('remote-stroke', (data) => {
          drawRemoteLine(data);
      });
    */
}

function stopMockCollaboration() {
    if (mockCollaborationInterval) {
        clearInterval(mockCollaborationInterval);
        mockCollaborationInterval = null;
    }
    if (remoteCursorsContainer) {
        remoteCursorsContainer.innerHTML = '';
    }
}

// ================= CUSTOM POPUPS =================
function showAlert(message) {
    document.getElementById('alert-message').textContent = message;
    document.getElementById('alert-popup').classList.remove('hidden');
}

function closeAlert() {
    document.getElementById('alert-popup').classList.add('hidden');
}
