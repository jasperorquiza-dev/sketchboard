/**
 * NetManager.js (Serverless Version)
 * Unified Networking Library for Aurazre Suite
 * Uses PeerJS Cloud for zero-abuse networking.
 */

class NetManager {
    constructor(config = {}) {
        this.roomId = config.roomId || 'default';
        this.namespace = config.namespace || 'az'; 
        this.userId = config.userId || Math.random().toString(36).substr(2, 6);
        this.isHost = config.isHost || false;
        
        // Predictable ID for the Host, Random for Viewers
        this.hostId = `${this.namespace}-${this.roomId}-host`;
        this.peerId = this.isHost ? this.hostId : `${this.hostId}-${this.userId}`;

        this.peer = null;
        this.connections = new Map();
        
        this.callbacks = {
            onOpen: config.onOpen || (() => {}),
            onData: config.onData || (() => {}),
            onPeerJoined: config.onPeerJoined || (() => {}),
            onPeerLeft: config.onPeerLeft || (() => {}),
            onCall: config.onCall || (() => {}),
            onError: config.onError || (() => {})
        };

        this.peerConfig = {
            debug: 1,
            config: {
                'iceServers': [{ urls: 'stun:stun.l.google.com:19302' }]
            }
        };

        this.init();
    }

    init() {
        this.peer = new Peer(this.peerId, this.peerConfig);

        this.peer.on('open', (id) => {
            this.callbacks.onOpen(id);
            if (!this.isHost) {
                // Viewers connect to the Host immediately
                this.connect(this.hostId);
            }
        });

        this.peer.on('connection', (conn) => this._setupConnection(conn));
        
        this.peer.on('call', (call) => this.callbacks.onCall(call));

        this.peer.on('error', (err) => {
            if (err.type === 'peer-unavailable') return;
            this.callbacks.onError(err);
        });
        
        window.addEventListener('beforeunload', () => this.leave());
    }

    _setupConnection(conn) {
        conn.on('open', () => {
            this.connections.set(conn.peer, conn);
            this.callbacks.onPeerJoined(conn.peer);
            
            // If the HOST receives a connection, it should eventually 
            // tell other viewers about each other (for Sketch/Locate).
        });

        conn.on('data', (data) => this.callbacks.onData(conn.peer, data));

        const cleanup = () => {
            this.connections.delete(conn.peer);
            this.callbacks.onPeerLeft(conn.peer);
        };

        conn.on('close', cleanup);
        conn.on('error', cleanup);
    }

    connect(targetPeerId) {
        if (this.connections.has(targetPeerId) || targetPeerId === this.peerId) return;
        const conn = this.peer.connect(targetPeerId);
        this._setupConnection(conn);
    }

    broadcast(data) {
        this.connections.forEach(conn => {
            if (conn.open) conn.send(data);
        });
    }

    send(peerId, data) {
        const conn = this.connections.get(peerId);
        if (conn && conn.open) conn.send(data);
    }

    call(peerId, stream) {
        return this.peer.call(peerId, stream);
    }

    leave() {
        if (this.peer && !this.peer.destroyed) {
            this.peer.destroy();
            this.peer = null;
        }
    }
}
