class SketchApp {
    constructor() {
        const config = window.SKETCH_CONFIG || {};
        this.roomId = config.roomId || "";
        this.assetBase = config.assetBase || "";
        this.expired = Boolean(config.expired);
        this.intro = Boolean(config.intro);
        this.storeUrl = `${this.assetBase}/store.php`;
        this.sigUrl = `${this.assetBase}/sig.php`;
        this.canvas = document.getElementById("whiteboard");
        this.ctx = this.canvas ? this.canvas.getContext("2d", { alpha: false }) : null;
        this.dpr = Math.max(1, window.devicePixelRatio || 1);
        this.minScale = 0.03;
        this.maxScale = 8;
        this.defaultViewSize = 1200;
        this.viewportStorageKey = `sketch_viewport_${this.roomId}`;
        this.savedViewport = this.loadSavedViewport();
        this.hasAppliedInitialViewport = false;
        this.shouldAutoFitLoadedContent = !this.savedViewport;
        this.viewportSaveTimer = null;
        this.contentBounds = null;
        
        this.peer = null;
        this.peerId = `sketch-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
        this.connections = new Map();
        this.pendingConnections = new Set();
        this.nickname = (localStorage.getItem("sketch_nickname") || "").trim();
        this.peerColor = this.generateRandomColor();
        this.strokes = new Map();
        this.liveRemoteStrokes = new Map();
        this.messages = [];
        this.members = new Map();
        this.remoteCursors = new Map();
        this.messageIds = new Set();
        this.currentColor = "#111111";
        this.brushSize = 4;
        this.interactionMode = "draw";
        this.isEraser = false;
        this.scale = 1;
        this.offset = { x: 0, y: 0 };
        this.spacePressed = false;
        this.isDrawing = false;
        this.isPanning = false;
        this.pointerId = null;
        this.activePointers = new Map();
        this.pinchState = null;
        this.panStart = { x: 0, y: 0 };
        this.currentStroke = null;
        this.myStrokeIds = [];
        this.redoStack = [];
        this.lastSeenMessageAt = 0;
        this.lastCursorSentAt = 0;
        this.lastCursorWorld = null;
        this.cursorSendTimer = null;
        this.pendingCursorWorld = null;
        this.lastStrokeBroadcastAt = 0;
        this.sessionStarted = false;
        this.lastStateSyncAt = 0;
        this.actionQueue = [];
        this.isFlushingQueue = false;
        
        // Timers
        this.queueTimer = null;
        this.discoveryTimer = null;
        this.heartbeatTimer = null;
        this.presenceTimer = null;
        this.stateSyncTimer = null;
        this.zoomHoldTimer = null;
        this.zoomHoldDelayTimer = null;
        this.colorPicker = null;
        
        // State tracking
        this.localRevision = 0;
        
        this.init();
    }

    init() {
        if (this.canvas && this.ctx && !this.expired && this.roomId) {
            this.setupCanvas();
            this.setupColorPicker();
            this.setupEventListeners();
            this.updateToolButtons();
            this.updateUndoRedoButtons();
            this.updateMembersUI();
            this.checkIdentity();
        } else {
            this.hideLoader();
        }
    }

    setupCanvas() {
        const resize = () => {
            const rect = this.canvas.getBoundingClientRect();
            const w = Math.max(1, Math.round(rect.width * this.dpr));
            const h = Math.max(1, Math.round(rect.height * this.dpr));
            const isInitial = (this.canvas.width === 0 || this.canvas.height === 0);
            this.canvas.width = w;
            this.canvas.height = h;
            if (isInitial) {
                this.applyInitialViewport();
            } else {
                this.render();
            }
        };
        window.addEventListener("resize", () => window.requestAnimationFrame(resize));
        resize();
    }

    setupEventListeners() {
        this.canvas.addEventListener("pointerdown", e => this.onPointerDown(e));
        this.canvas.addEventListener("pointermove", e => {
            if (this.pointerId === null && !this.pinchState) {
                this.scheduleCursorSync(this.normalizePoint(this.screenToWorld(this.getCanvasPoint(e))));
            }
        });
        window.addEventListener("pointermove", e => this.onPointerMove(e));
        window.addEventListener("pointerup", e => this.onPointerUp(e));
        window.addEventListener("pointercancel", e => this.onPointerUp(e));
        this.canvas.addEventListener("wheel", e => {
            e.preventDefault();
            const factor = e.deltaY < 0 ? 1.12 : 0.88;
            this.zoomAt(factor, this.getCanvasPoint(e));
        }, { passive: false });

        this.canvas.addEventListener("contextmenu", e => e.preventDefault());
        window.addEventListener("blur", () => {
            this.finishInteraction();
            this.stopContinuousZoom();
        });
        window.addEventListener("beforeunload", () => this.leaveRoom());
        
        window.addEventListener("keydown", e => {
            if ((e.target.tagName || "").toLowerCase() === "input") return;
            const key = e.key.toLowerCase();
            if (key === " ") {
                this.spacePressed = true;
            }
            if (key === "f") {
                e.preventDefault();
                this.fitView();
            }
            if (key === "1") {
                this.interactionMode = "draw";
                this.isEraser = false;
                this.updateToolButtons();
            }
            if (key === "2") {
                this.interactionMode = "pan";
                this.isEraser = false;
                this.updateToolButtons();
            }
            if (key === "e") {
                this.interactionMode = "draw";
                this.isEraser = !this.isEraser;
                this.updateToolButtons();
            }
            if ((e.ctrlKey || e.metaKey) && key === "z") {
                e.preventDefault();
                this.undoAction();
            }
            if ((e.ctrlKey || e.metaKey) && key === "y") {
                e.preventDefault();
                this.redoAction();
            }
            if (key === "[") {
                e.preventDefault();
                this.setBrushSize(this.brushSize - 1);
            }
            if (key === "]") {
                e.preventDefault();
                this.setBrushSize(this.brushSize + 1);
            }
        });

        window.addEventListener("keyup", e => {
            if (e.key === " ") {
                this.spacePressed = false;
            }
        });

        document.getElementById("mode-toggle").addEventListener("click", () => {
            this.interactionMode = this.interactionMode === "pan" ? "draw" : "pan";
            this.isEraser = false;
            this.updateToolButtons();
        });
        document.getElementById("draw-mode-btn").addEventListener("click", () => {
            this.interactionMode = "draw";
            this.isEraser = false;
            this.updateToolButtons();
        });
        document.getElementById("eraser-btn").addEventListener("click", () => {
            this.interactionMode = "draw";
            this.isEraser = !this.isEraser;
            this.updateToolButtons();
        });

        document.getElementById("undo-btn").addEventListener("click", () => this.undoAction());
        document.getElementById("redo-btn").addEventListener("click", () => this.redoAction());
        document.getElementById("clear-btn").addEventListener("click", () => this.clearBoard());
        document.getElementById("export-btn").addEventListener("click", () => this.handleExport());

        this.setupHoldZoom(document.getElementById("zoom-in"), 1.12, 1.035);
        this.setupHoldZoom(document.getElementById("zoom-out"), 0.88, 0.965);
        document.getElementById("fit-view").addEventListener("click", () => this.fitView());

        document.getElementById("copy-link-btn").addEventListener("click", async () => {
            const btn = document.getElementById("copy-link-btn");
            try {
                const code = this.roomId.startsWith("sk-") ? this.roomId.slice(3) : this.roomId;
                await navigator.clipboard.writeText(code);
                btn.textContent = "COPIED";
            } catch (err) {
                btn.textContent = "FAILED";
            }
            window.setTimeout(() => {
                btn.textContent = "COPY";
            }, 1800);
        });

        document.getElementById("chat-btn").addEventListener("click", () => {
            document.getElementById("chat-widget").classList.toggle("hidden");
            document.getElementById("chat-dot").classList.add("hidden");
        });
        document.getElementById("close-chat").addEventListener("click", () => {
            document.getElementById("chat-widget").classList.add("hidden");
        });
        document.getElementById("chat-form").addEventListener("submit", e => {
            e.preventDefault();
            this.sendChatMessage();
        });

        document.querySelector(".connection-pill").addEventListener("click", () => {
            this.updateMembersUI();
            document.getElementById("members-modal").classList.remove("hidden");
        });
        document.getElementById("close-members").addEventListener("click", () => {
            document.getElementById("members-modal").classList.add("hidden");
        });

        this.setupSizePicker();
    }

    setupColorPicker() {
        const picker = document.getElementById("color-picker");
        const trigger = document.getElementById("color-picker-trigger");
        const swatch = document.getElementById("color-swatch");
        const popover = document.getElementById("color-picker-popover");
        const widget = document.getElementById("color-picker-widget");
        
        if (!(picker && trigger && swatch && popover && widget)) return;

        const updateColor = (color) => {
            this.setBrushColor(color);
            picker.value = this.currentColor;
            swatch.style.background = this.currentColor;
        };

        updateColor(this.currentColor);

        const initIro = () => {
            if (this.colorPicker || !window.iro || typeof window.iro.ColorPicker !== "function") {
                return Boolean(this.colorPicker);
            }
            this.colorPicker = new window.iro.ColorPicker(widget, {
                width: 180,
                color: this.currentColor,
                borderWidth: 3,
                borderColor: "#111111",
                layout: [
                    { component: window.iro.ui.Wheel },
                    { component: window.iro.ui.Slider, options: { sliderType: "value" } }
                ]
            });
            this.colorPicker.on("color:change", color => {
                updateColor(color.hexString);
            });
            return true;
        };

        if (!window.iro || typeof window.iro.ColorPicker !== "function") {
            picker.hidden = false;
            trigger.addEventListener("click", () => picker.click());
            picker.addEventListener("input", e => updateColor(e.target.value));
            return;
        }

        const positionPopover = () => {
            const tools = document.getElementById("main-tools");
            if (!tools) return;
            const toolsRect = tools.getBoundingClientRect();
            const triggerRect = trigger.getBoundingClientRect();
            const isMobile = window.matchMedia("(max-width: 600px)").matches;
            const width = isMobile ? 176 : 184;
            const left = Math.max(12, Math.min(window.innerWidth - width - 12, triggerRect.left + triggerRect.width / 2 - width / 2));
            popover.style.left = `${left}px`;
            if (isMobile) {
                const top = Math.max(12, toolsRect.top - popover.offsetHeight - 10);
                popover.style.top = `${top}px`;
            } else {
                popover.style.top = `${toolsRect.bottom + 10}px`;
            }
        };

        const showPopover = () => {
            popover.classList.add("open");
            popover.setAttribute("aria-hidden", "false");
            if (initIro()) {
                window.requestAnimationFrame(() => {
                    if (this.colorPicker && typeof this.colorPicker.resize === "function") {
                        this.colorPicker.resize(window.matchMedia("(max-width: 600px)").matches ? 142 : 150);
                    }
                    if (this.colorPicker && this.colorPicker.color) {
                        this.colorPicker.color.hexString = this.currentColor;
                    }
                    positionPopover();
                });
            }
        };

        const hidePopover = () => {
            popover.classList.remove("open");
            popover.setAttribute("aria-hidden", "true");
        };

        trigger.addEventListener("click", e => {
            e.preventDefault();
            popover.classList.contains("open") ? hidePopover() : showPopover();
        });

        picker.addEventListener("input", e => updateColor(e.target.value));

        document.addEventListener("pointerdown", e => {
            if (popover.classList.contains("open")) {
                if (!popover.contains(e.target) && !trigger.contains(e.target)) {
                    hidePopover();
                }
            }
        });

        window.addEventListener("keydown", e => {
            if (e.key === "Escape") hidePopover();
        });

        window.addEventListener("resize", () => {
            if (popover.classList.contains("open")) window.requestAnimationFrame(positionPopover);
        });

        window.addEventListener("scroll", () => {
            if (popover.classList.contains("open")) window.requestAnimationFrame(positionPopover);
        }, true);
    }

    setBrushColor(color) {
        this.currentColor = String(color || "#111111").toUpperCase();
        const preview = document.getElementById("size-preview");
        const swatch = document.getElementById("color-swatch");
        const picker = document.getElementById("color-picker");
        if (preview) preview.style.background = this.currentColor;
        if (swatch) swatch.style.background = this.currentColor;
        if (picker && picker.value !== this.currentColor) picker.value = this.currentColor;
    }

    setupSizePicker() {
        const toggle = document.getElementById("size-toggle");
        const popover = document.getElementById("size-picker-popover");
        const slider = document.getElementById("size-slider");
        if (!toggle || !popover || !slider) return;

        if (window.noUiSlider && !slider.noUiSlider) {
            window.noUiSlider.create(slider, {
                start: this.brushSize,
                step: 1,
                connect: [true, false],
                range: { min: 1, max: 100 },
                tooltips: {
                    to: val => `${Math.round(val)}`,
                    from: val => Number(val)
                }
            });
        }

        const positionPopover = () => {
            const tools = document.getElementById("main-tools");
            if (!tools) return;
            const toolsRect = tools.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            const isMobile = window.matchMedia("(max-width: 600px)").matches;
            const width = isMobile ? 176 : 184;
            const left = Math.max(12, Math.min(window.innerWidth - width - 12, toggleRect.left + toggleRect.width / 2 - width / 2));
            popover.style.left = `${left}px`;
            popover.style.top = isMobile ? `${Math.max(12, toolsRect.top - popover.offsetHeight - 10)}px` : `${toolsRect.bottom + 10}px`;
        };

        const showPopover = () => {
            popover.classList.add("open");
            popover.setAttribute("aria-hidden", "false");
            window.requestAnimationFrame(() => {
                positionPopover();
                if (slider.noUiSlider) {
                    slider.noUiSlider.set(this.brushSize);
                    const handle = slider.querySelector(".noUi-handle");
                    if (handle) handle.focus({ preventScroll: true });
                }
            });
        };

        const hidePopover = () => {
            if (popover.contains(document.activeElement)) {
                toggle.focus({ preventScroll: true });
            }
            popover.classList.remove("open");
            popover.setAttribute("aria-hidden", "true");
        };

        toggle.addEventListener("click", e => {
            e.preventDefault();
            popover.classList.contains("open") ? hidePopover() : showPopover();
        });

        if (slider.noUiSlider) {
            slider.noUiSlider.on("update", val => {
                this.setBrushSize(val[0], false);
            });
        }

        document.addEventListener("pointerdown", s => {
            if (popover.classList.contains("open")) {
                if (!popover.contains(s.target) && !toggle.contains(s.target)) {
                    hidePopover();
                }
            }
        });

        window.addEventListener("keydown", e => {
            if (e.key === "Escape") hidePopover();
        });

        window.addEventListener("resize", () => {
            if (popover.classList.contains("open")) window.requestAnimationFrame(positionPopover);
        });

        window.addEventListener("scroll", () => {
            if (popover.classList.contains("open")) window.requestAnimationFrame(positionPopover);
        }, true);

        this.setBrushSize(this.brushSize);
    }

    setBrushSize(size) {
        const val = Math.max(1, Math.min(100, parseInt(size, 10) || 1));
        this.brushSize = val;
        const preview = document.getElementById("size-preview");
        const valueDisplay = document.getElementById("size-preview-value");
        const slider = document.getElementById("size-slider");
        if (preview) {
            const sizePx = 4 + (this.brushSize - 1) / 99 * 30;
            preview.style.width = `${sizePx}px`;
            preview.style.height = `${sizePx}px`;
            preview.style.background = this.currentColor;
        }
        if (valueDisplay) {
            valueDisplay.textContent = String(this.brushSize);
        }
        if (slider && slider.noUiSlider) {
            if (Number(slider.noUiSlider.get()) !== this.brushSize) {
                slider.noUiSlider.set(this.brushSize);
            }
        }
    }

    setupHoldZoom(btn, rate, slowRate) {
        if (!btn) return;
        const stop = () => this.stopContinuousZoom();
        btn.addEventListener("pointerdown", e => {
            e.preventDefault();
            this.stopContinuousZoom();
            this.zoomAt(rate, this.getCanvasCenterPoint());
            this.zoomHoldDelayTimer = window.setTimeout(() => {
                this.zoomHoldTimer = window.setInterval(() => {
                    this.zoomAt(slowRate, this.getCanvasCenterPoint());
                }, 60);
            }, 220);
        });
        btn.addEventListener("pointerup", stop);
        btn.addEventListener("pointercancel", stop);
        btn.addEventListener("pointerleave", stop);
        btn.addEventListener("lostpointercapture", stop);
    }

    stopContinuousZoom() {
        window.clearTimeout(this.zoomHoldDelayTimer);
        window.clearInterval(this.zoomHoldTimer);
        this.zoomHoldDelayTimer = null;
        this.zoomHoldTimer = null;
    }

    getCanvasCenterPoint() {
        return { x: this.canvas.width / 2, y: this.canvas.height / 2 };
    }

    checkIdentity() {
        const modal = document.getElementById("name-modal");
        const input = document.getElementById("user-nickname");
        const btn = document.getElementById("join-btn");
        if (this.nickname) {
            input.value = this.nickname;
            modal.classList.add("hidden");
            this.startSession();
            return;
        }
        modal.classList.remove("hidden");
        this.hideLoader();
        input.focus();
        const join = () => {
            const nickname = input.value.trim();
            if (nickname) {
                this.nickname = nickname.slice(0, 20);
                localStorage.setItem("sketch_nickname", this.nickname);
                modal.classList.add("hidden");
                this.startSession();
            } else {
                input.focus();
            }
        };
        btn.addEventListener("click", join);
        input.addEventListener("keydown", e => {
            if (e.key === "Enter") {
                e.preventDefault();
                join();
            }
        });
    }

    async startSession() {
        if (!this.sessionStarted) {
            this.sessionStarted = true;
            await this.loadPersistentState();
            this.initPeer();
        }
    }

    initPeer() {
        if (typeof Peer !== "function") {
            this.setConnectionStatus(false);
            this.hideLoader();
            window.alert("PeerJS failed to load.");
            return;
        }
        this.peer = new Peer(this.peerId, {
            debug: 1,
            config: {
                iceServers: [
                    { urls: "stun:stun.l.google.com:19302" }
                ]
            }
        });
        this.peer.on("open", async () => {
            await this.syncPresence("join");
            await this.fetchPresence();
            this.startLoops();
            this.setConnectionStatus(true);
            this.hideLoader();
            if (this.intro) {
                window.history.replaceState({}, "", window.location.pathname);
            }
        });
        this.peer.on("connection", conn => this.setupConnection(conn));
        this.peer.on("error", err => {
            console.warn("[PeerJS]", err);
            this.setConnectionStatus(false);
        });
        this.peer.on("disconnected", () => {
            this.setConnectionStatus(false);
            this.peer.reconnect();
        });
    }

    startLoops() {
        this.queueTimer = window.setInterval(() => this.flushActionQueue(), 2000);
        this.discoveryTimer = window.setInterval(() => this.fetchPresence(), 5000);
        this.heartbeatTimer = window.setInterval(() => this.syncPresence("heartbeat"), 15000);
        this.presenceTimer = window.setInterval(() => this.broadcastCursor(true), 1200);
        this.stateSyncTimer = window.setInterval(() => this.pollStateSync(), 4000); // Periodic state sync fallback
    }

    async fetchPresence() {
        try {
            const data = await this.requestJson(`${this.sigUrl}?room=${encodeURIComponent(this.roomId)}&_=${Date.now()}`);
            if (!data.ok) throw new Error(data.error || "Presence failed");
            const peers = Array.isArray(data.peers) ? data.peers : [];
            this.updateMembersFromPresence(peers);
            peers.forEach(peer => {
                if (peer && peer.id && peer.id !== this.peerId && !this.connections.has(peer.id) && !this.pendingConnections.has(peer.id)) {
                    this.connectToPeer(peer.id);
                }
            });
            this.setConnectionStatus(true);
        } catch (err) {
            this.setConnectionStatus(false);
        }
    }

    updateMembersFromPresence(peers) {
        this.members = new Map();
        peers.forEach(peer => {
            if (peer && peer.id) {
                this.members.set(peer.id, {
                    id: peer.id,
                    name: peer.name || "Guest",
                    color: peer.color || "#7D5FFF",
                    cursor: peer.cursor || null
                });
            }
        });
        if (!this.members.has(this.peerId)) {
            this.members.set(this.peerId, {
                id: this.peerId,
                name: this.nickname,
                color: this.peerColor,
                cursor: this.lastCursorWorld
            });
        }
        this.renderRemoteCursors();
        this.updateMembersUI();
    }

    async syncPresence(action) {
        const payload = {
            action: action,
            id: this.peerId,
            name: this.nickname,
            color: this.peerColor,
            cursor: this.lastCursorWorld
        };
        if (action === "leave" && navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(`${this.sigUrl}?room=${encodeURIComponent(this.roomId)}`, new Blob([JSON.stringify(payload)], { type: "application/json" }))) {
                    return;
                }
            } catch (err) {}
        }
        try {
            await this.requestJson(`${this.sigUrl}?room=${encodeURIComponent(this.roomId)}`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
                keepalive: action === "leave"
            });
        } catch (err) {}
    }

    connectToPeer(peerId) {
        if (!this.peer || this.connections.has(peerId) || this.pendingConnections.has(peerId)) return;
        this.pendingConnections.add(peerId);
        const conn = this.peer.connect(peerId, { reliable: true });
        this.setupConnection(conn);
    }

    setupConnection(conn) {
        conn.on("open", () => {
            this.pendingConnections.delete(conn.peer);
            this.connections.set(conn.peer, conn);
            this.sendTo(conn.peer, {
                type: "identity",
                id: this.peerId,
                name: this.nickname,
                color: this.peerColor
            });
            this.sendFullState(conn.peer);
            this.updateMembersUI();
        });
        conn.on("data", data => this.handlePeerData(conn.peer, data));
        
        const cleanup = () => {
            this.pendingConnections.delete(conn.peer);
            if (this.connections.get(conn.peer) === conn) {
                this.connections.delete(conn.peer);
            }
            this.liveRemoteStrokes.forEach((stroke, strokeId) => {
                if (stroke.owner === conn.peer) {
                    this.liveRemoteStrokes.delete(strokeId);
                }
            });
            this.render();
            this.updateMembersUI();
        };
        conn.on("close", cleanup);
        conn.on("error", cleanup);
    }

    handlePeerData(peer, data) {
        if (!data || !data.type) return;
        const handlers = {
            "identity": () => {
                this.members.set(peer, {
                    id: peer,
                    name: data.name || "Guest",
                    color: data.color || "#7D5FFF",
                    cursor: null
                });
                this.updateMembersUI();
            },
            "full-state": () => {
                if ((data.sentAt || 0) >= this.lastStateSyncAt) {
                    this.lastStateSyncAt = data.sentAt || Date.now();
                    this.applyFullState(data.state || {});
                }
            },
            "stroke-live": () => {
                if (data.stroke && data.stroke.id) {
                    this.liveRemoteStrokes.set(data.stroke.id, data.stroke);
                    this.render();
                }
            },
            "stroke-final": () => {
                if (data.stroke && data.stroke.id) {
                    this.liveRemoteStrokes.delete(data.stroke.id);
                    this.strokes.set(data.stroke.id, data.stroke);
                    this.contentBounds = this.calculateStrokeBounds();
                    this.render();
                }
            },
            "stroke-remove": () => {
                this.liveRemoteStrokes.delete(data.strokeId);
                this.strokes.delete(data.strokeId);
                this.contentBounds = this.calculateStrokeBounds();
                this.render();
            },
            "board-clear": () => {
                this.strokes.clear();
                this.liveRemoteStrokes.clear();
                this.redoStack = [];
                this.contentBounds = null;
                this.render();
                this.updateUndoRedoButtons();
            },
            "cursor": () => {
                const member = this.members.get(peer);
                if (member) {
                    member.cursor = data.cursor || null;
                    this.renderRemoteCursors();
                }
            },
            "chat": () => {
                if (data.message && !this.messageIds.has(data.message.id)) {
                    this.messages.push(data.message);
                    this.messages = this.messages.slice(-60);
                    this.renderChatMessages();
                }
            }
        };
        if (handlers[data.type]) {
            handlers[data.type]();
        }
    }

    sendTo(peer, data) {
        const conn = this.connections.get(peer);
        if (conn && conn.open) {
            conn.send(data);
        }
    }

    broadcast(data) {
        this.connections.forEach(conn => {
            if (conn.open) {
                conn.send(data);
            }
        });
    }

    sendFullState(peer) {
        this.sendTo(peer, {
            type: "full-state",
            sentAt: Date.now(),
            state: {
                strokes: Array.from(this.strokes.values()),
                messages: this.messages.slice(-60),
                meta: {
                    bounds: this.contentBounds || this.calculateStrokeBounds()
                }
            }
        });
    }

    applyFullState(state) {
        this.localRevision = parseInt(state.revision || 0, 10);
        
        const newStrokes = new Map();
        (Array.isArray(state.strokes) ? state.strokes : [])
            .filter(s => s && s.id && Array.isArray(s.points))
            .sort((a, b) => Number(a.createdAt || 0) - Number(b.createdAt || 0))
            .forEach(s => newStrokes.set(s.id, s));
        
        this.strokes = newStrokes;
        this.messages = Array.isArray(state.messages) ? state.messages.slice(-60) : [];
        this.contentBounds = this.normalizeBounds(state.meta?.bounds) || this.calculateStrokeBounds();
        this.render();
        this.renderChatMessages();
        this.updateUndoRedoButtons();
        
        if (!this.hasAppliedInitialViewport && this.canvas.width > 0 && this.canvas.height > 0) {
            this.applyInitialViewport();
        } else if (this.shouldAutoFitLoadedContent && this.contentBounds) {
            this.shouldAutoFitLoadedContent = false;
            this.fitView();
        }
    }

    async loadPersistentState() {
        try {
            const res = await this.requestJson(`${this.storeUrl}?room=${encodeURIComponent(this.roomId)}&_=${Date.now()}`);
            if (!res.ok || !res.state) return;
            this.applyFullState(res.state);
        } catch (err) {}
    }

    enqueueAction(action) {
        if (!action || !action.action) return;
        if (action.action === "clear") {
            this.actionQueue = [action];
        } else if (action.action === "upsert-stroke" && action.stroke?.id) {
            this.actionQueue = this.actionQueue.filter(entry => {
                if (!entry) return false;
                if (entry.action === "clear") return true;
                if (entry.action === "upsert-stroke" || entry.action === "remove-stroke") {
                    return (entry.stroke?.id || entry.strokeId) !== action.stroke.id;
                }
                return true;
            });
            this.actionQueue.push(action);
        } else if (action.action === "remove-stroke" && action.strokeId) {
            this.actionQueue = this.actionQueue.filter(entry => {
                if (!entry) return false;
                if (entry.action === "clear") return true;
                if (entry.action === "upsert-stroke" || entry.action === "remove-stroke") {
                    return (entry.stroke?.id || entry.strokeId) !== action.strokeId;
                }
                return true;
            });
            this.actionQueue.push(action);
        } else if (action.action === "add-message" && action.message?.id) {
            const exists = this.actionQueue.some(entry => entry && entry.action === "add-message" && entry.message?.id === action.message.id);
            if (!exists) {
                this.actionQueue.push(action);
            }
        } else {
            this.actionQueue.push(action);
        }
        this.flushActionQueue();
    }

    async flushActionQueue() {
        if (this.isFlushingQueue || this.actionQueue.length === 0) return;
        this.isFlushingQueue = true;
        while (this.actionQueue.length > 0) {
            const batch = this.actionQueue.slice(0, 24);
            try {
                const res = await this.requestJson(`${this.storeUrl}?room=${encodeURIComponent(this.roomId)}`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ action: "batch", actions: batch })
                });
                if (res && res.ok && res.state) {
                    this.localRevision = parseInt(res.state.revision || 0, 10);
                }
                this.actionQueue.splice(0, batch.length);
            } catch (err) {
                break;
            }
        }
        this.isFlushingQueue = false;
    }

    async pollStateSync() {
        try {
            // Only poll if we have no active peer connections (WebRTC failing/NAT block)
            if (this.connections.size > 0) {
                return;
            }
            const res = await this.requestJson(`${this.storeUrl}?room=${encodeURIComponent(this.roomId)}&_=${Date.now()}`);
            if (res && res.ok && res.state) {
                const serverRevision = parseInt(res.state.revision || 0, 10);
                if (serverRevision > this.localRevision) {
                    this.applyFullState(res.state);
                }
            }
        } catch (e) {
            // Ignore
        }
    }

    requestJson(url, options = {}) {
        return fetch(url, {
            cache: "no-store",
            credentials: "same-origin",
            ...options
        }).then(async res => {
            const text = await res.text();
            const data = text ? JSON.parse(text) : {};
            return res.ok ? data : { ok: false, error: data.error || `HTTP ${res.status}` };
        });
    }

    hideLoader() {
        const loader = document.getElementById("page-loader");
        if (loader) loader.classList.add("hidden");
    }

    getCanvasPoint(e) {
        return this.getCanvasPointFromClient(e.clientX, e.clientY);
    }

    getCanvasPointFromClient(x, y) {
        const rect = this.canvas.getBoundingClientRect();
        return {
            x: (x - rect.left) * this.dpr,
            y: (y - rect.top) * this.dpr
        };
    }

    screenToWorld(p) {
        return {
            x: (p.x - this.offset.x) / (this.scale * this.dpr),
            y: (p.y - this.offset.y) / (this.scale * this.dpr)
        };
    }

    worldToScreen(p) {
        return {
            x: p.x * this.scale + this.offset.x / this.dpr,
            y: p.y * this.scale + this.offset.y / this.dpr
        };
    }

    normalizePoint(p) {
        if (!p) return null;
        const x = Number(p.x);
        const y = Number(p.y);
        return Number.isFinite(x) && Number.isFinite(y) ? {
            x: Math.max(-1000000, Math.min(1000000, Number(x.toFixed(2)))),
            y: Math.max(-1000000, Math.min(1000000, Number(y.toFixed(2))))
        } : null;
    }

    normalizeBounds(b) {
        if (!b) return null;
        const minX = Number(b.minX);
        const minY = Number(b.minY);
        const maxX = Number(b.maxX);
        const maxY = Number(b.maxY);
        return ![minX, minY, maxX, maxY].every(Number.isFinite) || minX > maxX || minY > maxY ? null : { minX, minY, maxX, maxY };
    }

    loadSavedViewport() {
        try {
            const data = localStorage.getItem(this.viewportStorageKey);
            if (!data) return null;
            const viewport = JSON.parse(data);
            return viewport && Number.isFinite(viewport.scale) && Number.isFinite(viewport.offsetX) && Number.isFinite(viewport.offsetY) ? {
                scale: viewport.scale,
                offsetX: viewport.offsetX,
                offsetY: viewport.offsetY
            } : null;
        } catch (err) {
            return null;
        }
    }

    applyInitialViewport() {
        if (this.hasAppliedInitialViewport) return;
        this.hasAppliedInitialViewport = true;
        if (this.savedViewport) {
            this.scale = Math.max(this.minScale, Math.min(this.savedViewport.scale, this.maxScale));
            this.offset.x = this.savedViewport.offsetX;
            this.offset.y = this.savedViewport.offsetY;
            this.render();
            return;
        }
        if (this.contentBounds || this.calculateStrokeBounds()) {
            this.shouldAutoFitLoadedContent = false;
        }
        this.fitView();
    }

    scheduleViewportSave() {
        window.clearTimeout(this.viewportSaveTimer);
        this.viewportSaveTimer = window.setTimeout(() => {
            try {
                localStorage.setItem(this.viewportStorageKey, JSON.stringify({
                    scale: Number(this.scale.toFixed(4)),
                    offsetX: Math.round(this.offset.x),
                    offsetY: Math.round(this.offset.y)
                }));
            } catch (err) {}
        }, 120);
    }

    getDefaultBounds() {
        const half = this.defaultViewSize / 2;
        return { minX: -half, minY: -half, maxX: half, maxY: half };
    }

    calculateStrokeBounds(tempStroke = null) {
        let bounds = null;
        const list = tempStroke ? [...this.strokes.values(), tempStroke] : Array.from(this.strokes.values());
        list.forEach(stroke => {
            const strokeBounds = this.getStrokeBounds(stroke);
            if (strokeBounds) {
                bounds = bounds ? {
                    minX: Math.min(bounds.minX, strokeBounds.minX),
                    minY: Math.min(bounds.minY, strokeBounds.minY),
                    maxX: Math.max(bounds.maxX, strokeBounds.maxX),
                    maxY: Math.max(bounds.maxY, strokeBounds.maxY)
                } : strokeBounds;
            }
        });
        return bounds;
    }

    getStrokeBounds(stroke) {
        if (!stroke || !Array.isArray(stroke.points) || stroke.points.length === 0) return null;
        let bounds = null;
        const size = Math.max(1, Number(stroke.size) || 1);
        stroke.points.forEach(p => {
            const pt = this.normalizePoint(p);
            if (!pt) return;
            const item = {
                minX: pt.x - size,
                minY: pt.y - size,
                maxX: pt.x + size,
                maxY: pt.y + size
            };
            bounds = bounds ? {
                minX: Math.min(bounds.minX, item.minX),
                minY: Math.min(bounds.minY, item.minY),
                maxX: Math.max(bounds.maxX, item.maxX),
                maxY: Math.max(bounds.maxY, item.maxY)
            } : item;
        });
        return bounds;
    }

    getTouchPointers() {
        return Array.from(this.activePointers.values()).filter(p => p.pointerType === "touch");
    }

    startPinchGesture() {
        const touchList = this.getTouchPointers();
        if (touchList.length < 2) return;
        this.finishInteraction();
        const [a, b] = touchList;
        const ptA = this.getCanvasPointFromClient(a.clientX, a.clientY);
        const ptB = this.getCanvasPointFromClient(b.clientX, b.clientY);
        const midpoint = {
            x: (ptA.x + ptB.x) / 2,
            y: (ptA.y + ptB.y) / 2
        };
        this.pinchState = {
            initialDistance: Math.max(1, Math.hypot(ptB.x - ptA.x, ptB.y - ptA.y)),
            initialScale: this.scale,
            worldAtMidpoint: this.screenToWorld(midpoint)
        };
    }

    updatePinchGesture() {
        const touchList = this.getTouchPointers();
        if (touchList.length < 2 || !this.pinchState) return;
        const [a, b] = touchList;
        const ptA = this.getCanvasPointFromClient(a.clientX, a.clientY);
        const ptB = this.getCanvasPointFromClient(b.clientX, b.clientY);
        const midX = (ptA.x + ptB.x) / 2;
        const midY = (ptA.y + ptB.y) / 2;
        const dist = Math.max(1, Math.hypot(ptB.x - ptA.x, ptB.y - ptA.y));
        const scale = Math.max(this.minScale, Math.min(this.pinchState.initialScale * (dist / this.pinchState.initialDistance), this.maxScale));
        this.scale = scale;
        this.offset.x = midX - this.pinchState.worldAtMidpoint.x * this.scale * this.dpr;
        this.offset.y = midY - this.pinchState.worldAtMidpoint.y * this.scale * this.dpr;
        this.render();
    }

    onPointerDown(e) {
        if (e.target !== this.canvas) return;
        this.activePointers.set(e.pointerId, {
            pointerId: e.pointerId,
            pointerType: e.pointerType,
            clientX: e.clientX,
            clientY: e.clientY
        });
        if (e.pointerType === "touch" && this.getTouchPointers().length >= 2) {
            this.startPinchGesture();
            e.preventDefault();
            return;
        }
        const pt = this.getCanvasPoint(e);
        if (this.interactionMode === "pan" || this.spacePressed || e.button === 1 || e.button === 2) {
            this.isPanning = true;
            this.pointerId = e.pointerId;
            this.panStart = {
                x: pt.x - this.offset.x,
                y: pt.y - this.offset.y
            };
            this.canvas.setPointerCapture(e.pointerId);
            e.preventDefault();
            return;
        }
        if (e.button !== 0) return;
        const worldPt = this.normalizePoint(this.screenToWorld(pt));
        if (!worldPt) return;
        const color = this.isEraser ? "#FFFFFF" : this.currentColor;
        this.isDrawing = true;
        this.pointerId = e.pointerId;
        this.currentStroke = {
            id: `stroke-${this.peerId}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
            owner: this.peerId,
            color: color,
            size: this.brushSize,
            points: [worldPt],
            createdAt: Date.now()
        };
        this.canvas.setPointerCapture(e.pointerId);
        this.lastStrokeBroadcastAt = 0;
        this.broadcastLiveStroke();
        this.scheduleCursorSync(worldPt);
        this.render();
        e.preventDefault();
    }

    onPointerMove(e) {
        if (this.activePointers.has(e.pointerId)) {
            this.activePointers.set(e.pointerId, {
                pointerId: e.pointerId,
                pointerType: e.pointerType,
                clientX: e.clientX,
                clientY: e.clientY
            });
        }
        if (this.pinchState && e.pointerType === "touch") {
            this.updatePinchGesture();
            e.preventDefault();
            return;
        }
        if (this.pointerId !== e.pointerId) {
            const pt = this.getCanvasPoint(e);
            this.scheduleCursorSync(this.normalizePoint(this.screenToWorld(pt)));
            return;
        }
        const pt = this.getCanvasPoint(e);
        if (this.isPanning) {
            this.offset.x = pt.x - this.panStart.x;
            this.offset.y = pt.y - this.panStart.y;
            this.scheduleCursorSync(this.normalizePoint(this.screenToWorld(pt)));
            this.render();
            e.preventDefault();
            return;
        }
        if (!this.isDrawing || !this.currentStroke) {
            this.scheduleCursorSync(this.normalizePoint(this.screenToWorld(pt)));
            return;
        }
        const worldPt = this.normalizePoint(this.screenToWorld(pt));
        if (!worldPt) return;
        const lastPt = this.currentStroke.points[this.currentStroke.points.length - 1];
        if (Math.hypot(worldPt.x - lastPt.x, worldPt.y - lastPt.y) < 0.5) return;
        this.currentStroke.points.push(worldPt);
        this.broadcastLiveStroke();
        this.scheduleCursorSync(worldPt);
        this.render();
        e.preventDefault();
    }

    onPointerUp(e) {
        this.activePointers.delete(e.pointerId);
        if (this.pinchState && e.pointerType === "touch") {
            if (this.getTouchPointers().length < 2) {
                this.pinchState = null;
                this.scheduleViewportSave();
            }
            e.preventDefault();
            return;
        }
        if (this.pointerId !== e.pointerId) return;
        if (this.isPanning) {
            this.finishInteraction();
            return;
        }
        if (!this.isDrawing || !this.currentStroke) {
            this.finishInteraction();
            return;
        }
        const stroke = this.currentStroke;
        this.isDrawing = false;
        this.currentStroke = null;
        this.pointerId = null;
        this.strokes.set(stroke.id, stroke);
        this.liveRemoteStrokes.delete(stroke.id);
        this.myStrokeIds.push(stroke.id);
        this.redoStack = [];
        this.scheduleCursorSync(stroke.points[stroke.points.length - 1]);
        this.broadcast({ type: "stroke-final", stroke: stroke });
        this.enqueueAction({ action: "upsert-stroke", stroke: stroke });
        this.contentBounds = this.calculateStrokeBounds();
        this.render();
        this.updateUndoRedoButtons();
    }

    finishInteraction() {
        this.isDrawing = false;
        this.isPanning = false;
        this.pointerId = null;
        this.currentStroke = null;
        this.scheduleViewportSave();
    }

    fitView() {
        const bounds = this.contentBounds || this.calculateStrokeBounds() || this.getDefaultBounds();
        const width = Math.max(120, bounds.maxX - bounds.minX);
        const height = Math.max(120, bounds.maxY - bounds.minY);
        const canvasW = Math.max(1, this.canvas.width / this.dpr - 160);
        const canvasH = Math.max(1, this.canvas.height / this.dpr - 160);
        this.scale = Math.max(this.minScale, Math.min(Math.min(canvasW / width, canvasH / height), this.maxScale));
        const midX = (bounds.minX + bounds.maxX) / 2;
        const midY = (bounds.minY + bounds.maxY) / 2;
        this.offset.x = (this.canvas.width / this.dpr / 2 - midX * this.scale) * this.dpr;
        this.offset.y = (this.canvas.height / this.dpr / 2 - midY * this.scale) * this.dpr;
        this.render();
        this.scheduleViewportSave();
    }

    zoomAt(factor, center) {
        const prevScale = this.scale;
        this.scale = Math.max(this.minScale, Math.min(this.scale * factor, this.maxScale));
        if (prevScale !== this.scale) {
            this.offset.x = center.x - (center.x - this.offset.x) * this.scale / prevScale;
            this.offset.y = center.y - (center.y - this.offset.y) * this.scale / prevScale;
            this.render();
            this.scheduleViewportSave();
        }
    }

    render() {
        const ctx = this.ctx;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = "#fdfaf2";
        ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        this.renderBackground(ctx);
        ctx.setTransform(this.scale * this.dpr, 0, 0, this.scale * this.dpr, this.offset.x, this.offset.y);
        for (const stroke of this.strokes.values()) {
            this.drawStroke(ctx, stroke);
        }
        for (const stroke of this.liveRemoteStrokes.values()) {
            this.drawStroke(ctx, stroke);
        }
        if (this.currentStroke) {
            this.drawStroke(ctx, this.currentStroke);
        }
        this.renderRemoteCursors();
    }

    renderBackground(ctx) {
        const spacing = this.getGridSpacing();
        const spacingMajor = 5 * spacing;
        const minPt = this.screenToWorld({ x: 0, y: 0 });
        const maxPt = this.screenToWorld({ x: this.canvas.width, y: this.canvas.height });
        ctx.setTransform(this.scale * this.dpr, 0, 0, this.scale * this.dpr, this.offset.x, this.offset.y);
        
        const drawGrid = (step, color, lineWidth = 1) => {
            const startX = Math.floor(minPt.x / step) * step;
            const endX = Math.ceil(maxPt.x / step) * step;
            const startY = Math.floor(minPt.y / step) * step;
            const endY = Math.ceil(maxPt.y / step) * step;
            ctx.beginPath();
            ctx.strokeStyle = color;
            ctx.lineWidth = lineWidth / (this.scale * this.dpr);
            for (let x = startX; x <= endX; x += step) {
                ctx.moveTo(x, startY);
                ctx.lineTo(x, endY);
            }
            for (let y = startY; y <= endY; y += step) {
                ctx.moveTo(startX, y);
                ctx.lineTo(endX, y);
            }
            ctx.stroke();
        };

        drawGrid(spacing, "#eadabe");
        drawGrid(spacingMajor, "#d4c4a8", 1.5);

        ctx.beginPath();
        ctx.strokeStyle = "#ff9e9e";
        ctx.lineWidth = 2 / (this.scale * this.dpr);
        ctx.moveTo(0, minPt.y);
        ctx.lineTo(0, maxPt.y);
        ctx.moveTo(minPt.x, 0);
        ctx.lineTo(maxPt.x, 0);
        ctx.stroke();
    }

    getGridSpacing() {
        const spacing = 44 / Math.max(this.scale, 0.0001);
        return [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000].find(val => val >= spacing) || 25000;
    }

    drawStroke(ctx, stroke) {
        if (!stroke || !Array.isArray(stroke.points) || stroke.points.length === 0) return;
        ctx.strokeStyle = stroke.color;
        ctx.lineWidth = stroke.size;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
        ctx.beginPath();
        ctx.moveTo(stroke.points[0].x, stroke.points[0].y);
        if (stroke.points.length === 1) {
            ctx.lineTo(stroke.points[0].x + 0.01, stroke.points[0].y + 0.01);
            ctx.stroke();
            return;
        }
        if (stroke.points.length === 2) {
            ctx.lineTo(stroke.points[1].x, stroke.points[1].y);
            ctx.stroke();
            return;
        }
        for (let i = 1; i < stroke.points.length - 2; i += 1) {
            const curr = stroke.points[i];
            const next = stroke.points[i + 1];
            const midX = (curr.x + next.x) / 2;
            const midY = (curr.y + next.y) / 2;
            ctx.quadraticCurveTo(curr.x, curr.y, midX, midY);
        }
        const penultimate = stroke.points[stroke.points.length - 2];
        const last = stroke.points[stroke.points.length - 1];
        ctx.quadraticCurveTo(penultimate.x, penultimate.y, last.x, last.y);
        ctx.stroke();
    }

    broadcastLiveStroke() {
        if (!this.currentStroke) return;
        const now = Date.now();
        if (now - this.lastStrokeBroadcastAt >= 16) {
            this.lastStrokeBroadcastAt = now;
            this.broadcast({ type: "stroke-live", stroke: this.currentStroke });
        }
    }

    undoAction() {
        if (this.isDrawing) return;
        while (this.myStrokeIds.length > 0) {
            const id = this.myStrokeIds.pop();
            const stroke = this.strokes.get(id);
            if (stroke) {
                this.strokes.delete(id);
                this.redoStack.push(stroke);
                this.contentBounds = this.calculateStrokeBounds();
                this.broadcast({ type: "stroke-remove", strokeId: id });
                this.enqueueAction({ action: "remove-stroke", strokeId: id });
                this.render();
                this.updateUndoRedoButtons();
                break;
            }
        }
    }

    redoAction() {
        if (this.isDrawing || this.redoStack.length === 0) return;
        const stroke = this.redoStack.pop();
        this.strokes.set(stroke.id, stroke);
        this.myStrokeIds.push(stroke.id);
        this.contentBounds = this.calculateStrokeBounds();
        this.broadcast({ type: "stroke-final", stroke: stroke });
        this.enqueueAction({ action: "upsert-stroke", stroke: stroke });
        this.render();
        this.updateUndoRedoButtons();
    }

    clearBoard() {
        if (window.confirm("Clear the whole board for everyone?")) {
            this.strokes.clear();
            this.liveRemoteStrokes.clear();
            this.myStrokeIds = [];
            this.redoStack = [];
            this.contentBounds = null;
            this.broadcast({ type: "board-clear" });
            this.enqueueAction({ action: "clear" });
            this.render();
            this.updateUndoRedoButtons();
        }
    }

    handleExport() {
        if (this.strokes.size === 0) {
            window.alert("The board is empty. Draw something first.");
            return;
        }
        const bounds = this.findStrokeBounds();
        const w = Math.max(1, Math.ceil(bounds.maxX - bounds.minX + 80));
        const h = Math.max(1, Math.ceil(bounds.maxY - bounds.minY + 80));
        const scale = Math.min(1, 4096 / Math.max(w, h));
        const canvasW = Math.max(1, Math.round(w * scale));
        const canvasH = Math.max(1, Math.round(h * scale));
        
        const exportCanvas = document.createElement("canvas");
        exportCanvas.width = canvasW;
        exportCanvas.height = canvasH;
        const ctx = exportCanvas.getContext("2d", { alpha: false });
        ctx.fillStyle = "#FFFFFF";
        ctx.fillRect(0, 0, canvasW, canvasH);
        ctx.scale(scale, scale);
        ctx.translate(40 - bounds.minX, 40 - bounds.minY);
        for (const stroke of this.strokes.values()) {
            this.drawStroke(ctx, stroke);
        }
        
        const link = document.createElement("a");
        link.download = `sketch-${this.roomId}-${Date.now()}.png`;
        link.href = exportCanvas.toDataURL("image/png");
        link.click();
    }

    findStrokeBounds() {
        return this.contentBounds || this.calculateStrokeBounds() || this.getDefaultBounds();
    }

    sendChatMessage() {
        const input = document.getElementById("chat-input");
        const val = input.value.trim();
        if (!val) return;
        const msg = {
            id: `msg-${this.peerId}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            name: this.nickname,
            text: val.slice(0, 400),
            createdAt: Date.now()
        };
        this.messages.push(msg);
        this.messages = this.messages.slice(-60);
        this.broadcast({ type: "chat", message: msg });
        this.enqueueAction({ action: "add-message", message: msg });
        this.renderChatMessages();
        input.value = "";
        document.getElementById("chat-dot").classList.add("hidden");
    }

    renderChatMessages() {
        const list = document.getElementById("chat-messages");
        if (!list) return;
        const countBefore = this.messageIds.size;
        list.innerHTML = "";
        this.messageIds = new Set();
        this.messages.slice().sort((a, b) => Number(a.createdAt || 0) - Number(b.createdAt || 0)).forEach(msg => {
            if (!msg || !msg.id) return;
            this.messageIds.add(msg.id);
            const isMe = msg.name === this.nickname;
            const div = document.createElement("div");
            div.className = "msg " + (isMe ? "sent" : "received");
            div.innerHTML = `<div class="msg-author">${this.escapeHtml(msg.name || "Guest")}</div><div>${this.escapeHtml(msg.text || "")}</div>`;
            list.appendChild(div);
        });
        list.scrollTop = list.scrollHeight;
        const lastMsg = this.messages[this.messages.length - 1];
        const isChatHidden = document.getElementById("chat-widget").classList.contains("hidden");
        if (lastMsg && isChatHidden && lastMsg.name !== this.nickname && this.messages.length >= countBefore && Number(lastMsg.createdAt || 0) > this.lastSeenMessageAt) {
            document.getElementById("chat-dot").classList.remove("hidden");
        }
        if (lastMsg) {
            this.lastSeenMessageAt = Math.max(this.lastSeenMessageAt, Number(lastMsg.createdAt || 0));
        }
    }

    scheduleCursorSync(p) {
        if (!p) return;
        if (this.lastCursorWorld) {
            const dx = p.x - this.lastCursorWorld.x;
            const dy = p.y - this.lastCursorWorld.y;
            if (Math.hypot(dx, dy) < 6) return;
        }
        this.pendingCursorWorld = p;
        const elapsed = Date.now() - this.lastCursorSentAt;
        if (elapsed >= 24) {
            this.flushCursorSync();
        } else {
            window.clearTimeout(this.cursorSendTimer);
            this.cursorSendTimer = window.setTimeout(() => this.flushCursorSync(), 24 - elapsed);
        }
    }

    flushCursorSync() {
        if (!this.pendingCursorWorld) return;
        this.lastCursorSentAt = Date.now();
        this.lastCursorWorld = this.pendingCursorWorld;
        const p = this.pendingCursorWorld;
        this.pendingCursorWorld = null;
        const me = this.members.get(this.peerId);
        if (me) me.cursor = p;
        this.broadcast({ type: "cursor", cursor: p });
    }

    broadcastCursor(force = false) {
        if (force) {
            if (this.lastCursorWorld) {
                this.broadcast({ type: "cursor", cursor: this.lastCursorWorld });
            }
        } else {
            this.flushCursorSync();
        }
    }

    renderRemoteCursors() {
        const container = document.getElementById("cursors-container");
        if (!container) return;
        const activeIds = new Set();
        this.members.forEach((member, id) => {
            if (id === this.peerId || !member.cursor) return;
            activeIds.add(id);
            let item = this.remoteCursors.get(id);
            if (!item) {
                const el = document.createElement("div");
                el.className = "remote-cursor";
                const label = document.createElement("div");
                label.className = "cursor-label";
                el.appendChild(label);
                container.appendChild(el);
                item = { element: el, label: label };
                this.remoteCursors.set(id, item);
            }
            item.element.style.background = member.color || "#7D5FFF";
            item.label.textContent = member.name || "Guest";
            const screenPt = this.worldToScreen(member.cursor);
            item.element.style.left = `${screenPt.x}px`;
            item.element.style.top = `${screenPt.y}px`;
        });
        this.remoteCursors.forEach((item, id) => {
            if (!activeIds.has(id)) {
                item.element.remove();
                this.remoteCursors.delete(id);
            }
        });
    }

    updateMembersUI() {
        const list = document.getElementById("members-list");
        if (!list) return;
        list.innerHTML = "";
        const listData = Array.from(this.members.values()).sort((a, b) => {
            if (a.id === this.peerId) return -1;
            if (b.id === this.peerId) return 1;
            return String(a.name || "Guest").localeCompare(String(b.name || "Guest"));
        });
        listData.forEach(member => {
            const div = document.createElement("div");
            div.className = "member-item";
            div.innerHTML = `<div class="member-color" style="background:${member.color || "#7D5FFF"}"></div><span>${this.escapeHtml(member.name || "Guest")}</span>${member.id === this.peerId ? '<span class="member-you">YOU</span>' : ""}`;
            list.appendChild(div);
        });
        const peerCount = document.getElementById("peer-count");
        if (peerCount) {
            peerCount.textContent = String(Math.max(1, listData.length || 1));
        }
    }

    setConnectionStatus(status) {
        const el = document.getElementById("connection-status");
        if (el) {
            el.classList.toggle("online", status);
            el.classList.toggle("offline", !status);
        }
    }

    updateToolButtons() {
        const toggle = document.getElementById("mode-toggle");
        const drawBtn = document.getElementById("draw-mode-btn");
        const eraserBtn = document.getElementById("eraser-btn");
        if (toggle) toggle.classList.toggle("active", this.interactionMode === "pan");
        if (drawBtn) drawBtn.classList.toggle("active", this.interactionMode === "draw" && !this.isEraser);
        if (eraserBtn) eraserBtn.classList.toggle("active", this.isEraser);
    }

    updateUndoRedoButtons() {
        const undo = document.getElementById("undo-btn");
        const redo = document.getElementById("redo-btn");
        if (undo) undo.style.opacity = this.myStrokeIds.length > 0 ? "1" : "0.35";
        if (redo) redo.style.opacity = this.redoStack.length > 0 ? "1" : "0.35";
    }

    leaveRoom() {
        this.stopContinuousZoom();
        window.clearTimeout(this.cursorSendTimer);
        window.clearTimeout(this.viewportSaveTimer);
        window.clearInterval(this.queueTimer);
        window.clearInterval(this.discoveryTimer);
        window.clearInterval(this.heartbeatTimer);
        window.clearInterval(this.presenceTimer);
        window.clearInterval(this.stateSyncTimer);
        this.syncPresence("leave");
        if (this.peer && !this.peer.destroyed) {
            this.peer.destroy();
        }
    }

    buildClientId() {
        return `client-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    }

    generateRandomColor() {
        const colors = ["#FF3366", "#33FF99", "#7D5FFF", "#FFDE00", "#FF8C00", "#00D4FF"];
        return colors[Math.floor(Math.random() * colors.length)];
    }

    escapeHtml(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }
}

window.SketchApp = SketchApp;
window.addEventListener("load", () => {
    new SketchApp();
});