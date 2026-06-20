# Sketchboard - Collaborative Whiteboard Studio

Sketchboard is a premium, web-based collaborative drawing and brainstorming studio styled with a beautiful classic notebook/stationery aesthetic. Designed for seamless real-time coordination, it allows multiple users to sketch, chat, and share ideas on a responsive, digital ruled-paper canvas.

---

## 1. Core Features

### 🎨 Interactive Drawing Canvas
* **Drawing & Eraser Tools:** Smooth, responsive brush stroke rendering with drawing and eraser modes.
* **Aesthetic Controls:** Interactive color popover featuring a custom color-wheel picker, along with quick brush size controls.
* **Canvas Navigation:** Dedicated movement mode (hand grab tool) to pan across the whiteboard space, zoom controls, and a quick-fit view option.
* **Exporting Work:** Export drawing canvases directly as PNG images for storage or sharing.

### 👥 Real-Time Collaboration & Chat
* **Peer-to-Peer Syncing:** Utilizes robust, low-latency peer connection technologies (PeerJS) to sync brush strokes and movement coordinates across all participants in real time.
* **Room-Based Access:** Unique room links allow instant group access. User nicknames are tracked automatically within the session.
* **Built-in Chat Room:** Inline messaging allows members to chat, coordinate, and share notes side-by-side with the drawing canvas.

### 📂 Automations & Lifecycles
* **Room Cleanup:** Automated logic to purge stale peer sessions, inactive temporary files, and expired rooms, maintaining high performance and server efficiency.

---

## 2. Security & Data Protection

The system is built with industry-standard, multi-layered security measures to protect account credentials, room drawing data, and session integrity:

### 🔒 Encryption at Rest
* **Whiteboard State Encryption:** All drawing stroke data and coordinates are encrypted using **AES-256-CBC** before being written to the database. Even in the event of database access compromise, canvas data remains unreadable.
* **Dynamic Key Derivation:** To ensure isolated environments, a unique 32-byte encryption key is derived per whiteboard room using **PBKDF2-HMAC-SHA256**.
* **Temporary Data Security:** Internal scratchpad and temporary JSON files are locked down using folder-level server blocks (`.htaccess`) and transparent AES-256 disk encryption.

### 🛡️ Authentication & Access Control
* **Password Hardening:** Credentials are secure at rest via strong native **BCrypt** hashing (`password_hash`).
* **Interactive Complexity Enforcement:** Registration forms feature real-time visual checklist indicators to ensure user passwords contain at least 8 characters, an uppercase letter, a lowercase letter, and a digit.
* **Session Protection:** Session identifiers are regenerated (`session_regenerate_id`) immediately upon login to neutralize session fixation threats. Cookies are locked down via `HttpOnly` and `SameSite` flags.
* **CSRF Mitigation:** Anti-CSRF cryptographic tokens are generated and validated across all state-mutating actions (login, registry, password resets, board modifications) to block cross-site execution vectors.

### ⚡ Rate Limiting & Throttling
* **IP Throttling Database:** Critical entrypoints (authentication, register, verification) are rate-limited to a maximum of **5 requests per minute per IP address** to stop automated brute force and credential stuffing scripts.

### 🌐 Secure HTTP Headers
The application injects hardened security headers globally:
* `X-Frame-Options: DENY` — Blocks clickjacking by preventing external page frame embedding.
* `X-Content-Type-Options: nosniff` — Neutralizes MIME-type sniffing vulnerabilities.
* `X-XSS-Protection: 1; mode=block` — Enforces built-in browser scripting filters.
* `Referrer-Policy: same-origin` — Restricts cross-origin resource referrers.
