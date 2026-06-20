# Sketchboard 2 - Security Features Documentation

This document outlines the security architecture and hardening measures implemented in the Sketchboard 2 whiteboard application to secure user data, authentication, real-time collaboration endpoints, and server infrastructure.

---

## 1. Authentication & Session Hardening
* **Password Hashing:** User passwords are encrypted and verified using PHP's native `password_hash()` and `password_verify()` functions with the cryptographically secure **BCRYPT** algorithm.
* **Session Fixation Prevention:** After successful login, the session identifier is regenerated via `session_regenerate_id(true)` to prevent session fixation attacks.
* **Endpoint Protection:** All primary board endpoints, real-time sync engines (`store.php`), and signalling APIs (`sig.php`) strictly validate the user's active session (`$_SESSION['user_id']`). Unauthenticated requests are immediately blocked with an HTTP `401 Unauthorized` response.
* **Secure Cookie Flags:** PHP session cookies are hardened with:
  * `HttpOnly=True`: Prevents client-side scripts from accessing session tokens, mitigating XSS token theft.
  * `SameSite=Lax`: Blocks cross-site cookie transmission to mitigate CSRF vectors.
  * `Secure=True`: Enforced dynamically when HTTPS is active to ensure credentials are never transmitted over unencrypted HTTP.

---

## 2. Encryption at Rest & Room File Protection
* **Field-Level AES-256-CBC Encryption:** Board stroke configurations and drawing state sequences are encrypted using AES-256 in CBC mode before being saved to the database. Even if the database is fully compromised, the drawing metadata remains unreadable.
* **Dynamic Key Derivation:** To avoid static server-wide keys, a unique 32-byte encryption key is derived for each whiteboard room. This is achieved using **PBKDF2-HMAC-SHA256** combined with a static server-side master key (`SKETCH_SECRET_KEY`) and the room-specific code acting as a salt.
* **Active Room File Security:** Active peer data and temporary room files stored in the `rooms_data/` directory are fully secured:
  * **Directory Access Controls:** A `.htaccess` file containing `Deny from all` blocks all direct web requests to the `rooms_data/` folder, preventing unauthorized file downloads and URL guessing.
  * **AES-256 JSON Encryption:** The JSON content inside temporary room files is transparently encrypted at rest on disk using the same room-specific PBKDF2 derived keys.
* **Transparent Decryption & Upgrade Path:** Encrypted board states and room files are transparently decrypted in-memory during load. The parser handles existing plain JSON files gracefully, automatically upgrading them to encrypted format upon the next update.

---

## 3. Cross-Site Request Forgery (CSRF) Protection
* **Cryptographic CSRF Tokens:** HTML forms (Login, Register, Password Reset, Verification, Board Creation, Board Deletion) inject a cryptographically secure, random 32-byte CSRF token generated in `bootstrap.php`. Incoming `POST` requests validate this token against the active session before processing data.
* **Origin/Referer Validation:** For state mutation requests directed at API-like endpoints (such as `store.php` and `sig.php` using AJAX/fetch), the server validates that the request `Origin` matches the target server host, preventing cross-origin form submission or JSON posting.

---

## 4. Rate Limiting & Brute Force Prevention
* **IP-Based Throttling:** Authentication endpoints (Login, Registration, Password Reset requests, and Reset submissions) are secured using an IP-based rate limiter database table (`rate_limits`).
* **Threshold Limit:** Restricts critical actions to a maximum of **5 requests per minute per IP address**.
* **Action:** Exceeding this rate returns a rate-limit block, notifying the client and throttling brute force or credential stuffing attacks.

---

## 5. Password Hardening & Complexity Validation
* **Strict Complexity Checks:** Password input fields enforce validation policies during registration and password resets.
* **Criteria:** Passwords must be at least **8 characters** long and contain at least one uppercase letter, one lowercase letter, and one number.

---

## 6. Secure HTTP Headers
The application enforces standard security headers at the bootstrap level to protect users' browsers:
* **`X-Frame-Options: DENY`**: Protects the whiteboard application against clickjacking by preventing it from being embedded in an iframe on external sites.
* **`X-Content-Type-Options: nosniff`**: Prevents browser MIME-sniffing vulnerabilities.
* **`X-XSS-Protection: 1; mode=block`**: Activates built-in browser cross-site scripting filters.
* **`Referrer-Policy: same-origin`**: Controls how much referrer information is shared when navigating away from the page.
