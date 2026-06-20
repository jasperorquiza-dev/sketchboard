const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn } = require("node:child_process");
const { WebSocket } = require("ws");

const testPort = 3210;
const testDbPath = path.join(os.tmpdir(), `sketchboard-test-${Date.now()}.db`);

let serverProcess = null;

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForServer(url, attempts = 30) {
  for (let index = 0; index < attempts; index += 1) {
    try {
      const response = await fetch(url);
      if (response.ok) {
        return;
      }
    } catch (_error) {
      // Retry until the process is ready.
    }

    await wait(250);
  }

  throw new Error("Server did not become ready in time.");
}

async function jsonFetch(pathname, options = {}) {
  const response = await fetch(`http://127.0.0.1:${testPort}${pathname}`, options);
  const data = await response.json().catch(() => ({}));

  return {
    response,
    data
  };
}

function createCookieHeader(response) {
  const setCookie = response.headers.get("set-cookie");
  return setCookie ? setCookie.split(";")[0] : "";
}

test.before(async () => {
  serverProcess = spawn(process.execPath, ["server.js"], {
    cwd: path.join(__dirname, ".."),
    env: {
      ...process.env,
      PORT: String(testPort),
      DB_PATH: testDbPath,
      JWT_SECRET: "test-secret-not-for-production",
      LOG_LEVEL: "silent",
      NODE_ENV: "test"
    },
    stdio: "ignore"
  });

  await waitForServer(`http://127.0.0.1:${testPort}/api/ready`);
});

test.after(async () => {
  if (serverProcess && !serverProcess.killed) {
    serverProcess.kill("SIGTERM");
    await wait(750);
  }

  if (fs.existsSync(testDbPath)) {
    fs.unlinkSync(testDbPath);
  }
});

test("health and readiness endpoints respond", async () => {
  const health = await jsonFetch("/api/health");
  const ready = await jsonFetch("/api/ready");

  assert.equal(health.response.status, 200);
  assert.equal(health.data.ok, true);
  assert.equal(ready.response.status, 200);
  assert.equal(ready.data.ok, true);
});

test("register, login, me, room create, room join, and logout flow works", async () => {
  const suffix = Date.now();
  const username = `api${suffix}`;
  const password = "Password1!";

  const register = await jsonFetch("/api/auth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      username,
      email: `${username}@example.com`,
      password,
      confirmPassword: password
    })
  });
  assert.equal(register.response.status, 201);

  const invalidLogin = await jsonFetch("/api/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      username,
      password: "WrongPassword1!"
    })
  });
  assert.equal(invalidLogin.response.status, 401);
  assert.ok(invalidLogin.data.requestId);

  const login = await jsonFetch("/api/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      username,
      password
    })
  });
  assert.equal(login.response.status, 200);

  const cookie = createCookieHeader(login.response);
  assert.ok(cookie.includes("sketchboard_token="));

  const me = await jsonFetch("/api/auth/me", {
    headers: { cookie }
  });
  assert.equal(me.response.status, 200);
  assert.equal(me.data.user.username, username);

  const roomCreate = await jsonFetch("/api/rooms", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      cookie
    },
    body: "{}"
  });
  assert.equal(roomCreate.response.status, 201);
  assert.match(roomCreate.data.room.code, /^[A-Z0-9]{6}$/);

  const roomJoin = await jsonFetch("/api/rooms/join", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      cookie
    },
    body: JSON.stringify({ code: roomCreate.data.room.code })
  });
  assert.equal(roomJoin.response.status, 200);

  const logout = await jsonFetch("/api/auth/logout", {
    method: "POST",
    headers: { cookie }
  });
  assert.equal(logout.response.status, 200);
});

test("websocket rejects unauthenticated access", async () => {
  await assert.rejects(
    async () => {
      await new Promise((resolve, reject) => {
        const socket = new WebSocket(`ws://127.0.0.1:${testPort}/ws?room=ABC123`);
        socket.on("open", resolve);
        socket.on("error", reject);
        socket.on("close", () => reject(new Error("closed")));
      });
    },
    /closed|socket hang up/i
  );
});

test("websocket presence, draw sync, clear sync, and reconnect resync work", async () => {
  const suffix = Date.now();
  const username = `ws${suffix}`;
  const password = "Password1!";

  await jsonFetch("/api/auth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      username,
      email: `${username}@example.com`,
      password,
      confirmPassword: password
    })
  });

  const login = await jsonFetch("/api/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ username, password })
  });
  const cookie = createCookieHeader(login.response);

  const roomCreate = await jsonFetch("/api/rooms", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      cookie
    },
    body: "{}"
  });
  const roomCode = roomCreate.data.room.code;

  function openSocket() {
    return new WebSocket(`ws://127.0.0.1:${testPort}/ws?room=${roomCode}`, {
      headers: { Cookie: cookie }
    });
  }

  const socketA = openSocket();
  const socketB = openSocket();

  const messagesB = [];
  let initA = null;
  let initB = null;

  await new Promise((resolve, reject) => {
    let readyCount = 0;

    const finish = () => {
      readyCount += 1;
      if (readyCount === 2) {
        resolve();
      }
    };

    socketA.on("message", (raw) => {
      const message = JSON.parse(raw.toString());
      if (message.t === "init") {
        initA = message;
        finish();
      }
    });

    socketB.on("message", (raw) => {
      const message = JSON.parse(raw.toString());
      messagesB.push(message);
      if (message.t === "init") {
        initB = message;
        finish();
      }
    });

    socketA.on("error", reject);
    socketB.on("error", reject);
    setTimeout(() => reject(new Error("socket init timeout")), 5000);
  });

  assert.equal(initA.t, "init");
  assert.equal(initB.t, "init");

  socketA.send(JSON.stringify({ t: "d", m: "p", c: "#123456", z: 4, s: [0.1, 0.2, 0.3, 0.4] }));
  socketA.send(JSON.stringify({ t: "cl" }));
  await wait(400);

  assert.ok(messagesB.some((message) => message.t === "presence"));
  assert.ok(messagesB.some((message) => message.t === "d"));
  assert.ok(messagesB.some((message) => message.t === "cl"));

  socketA.send(JSON.stringify({ t: "d", m: "p", c: "#654321", z: 6, s: [0.2, 0.2, 0.4, 0.5] }));
  await wait(1400);
  socketA.close();
  socketB.close();
  await wait(300);

  const socketC = openSocket();
  const initC = await new Promise((resolve, reject) => {
    socketC.on("message", (raw) => {
      const message = JSON.parse(raw.toString());
      if (message.t === "init") {
        resolve(message);
      }
    });
    socketC.on("error", reject);
    setTimeout(() => reject(new Error("socket reconnect timeout")), 5000);
  });

  assert.equal(Array.isArray(initC.events), true);
  assert.equal(initC.events.length, 1);
  assert.equal(initC.events[0].t, "d");
  assert.deepEqual(initC.events[0].s, [0.2, 0.2, 0.4, 0.5]);

  socketC.close();
});
