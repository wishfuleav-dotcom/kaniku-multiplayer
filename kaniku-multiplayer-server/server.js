/**
 * KANIKU — Server multiplayer (WebSocket).
 *
 * Satu room per map (map-sakura / map-salju / map-pantai): pemain di map yang
 * berbeda tidak saling lihat. Server ini HANYA me-relay pesan antar pemain di
 * room yang sama — skor tetap dikirim game ke games.php seperti biasa, dan
 * tidak ada data yang disimpan di server (tidak ada database).
 *
 * Protokol (JSON, ringan untuk HP low):
 *   Client -> server:
 *     hello { room, name, avatar }   — gabung room saat masuk lobby
 *     pos   { x, y, dir, moving, score, answered, state } — posisi berkala
 *     start {}                        — admin menekan "Mulai"
 *     reset {}                        — admin mereset permainan
 *     bye   {}                        — pemain keluar (opsional; disconnect
 *                                      TCP juga terdeteksi sebagai keluar)
 *   Server -> client (di-relay ke semua pemain lain di room yang sama):
 *     hello { from, name, avatar }    — pemain baru (balas dengan pos)
 *     pos   { from, ... }             — posisi pemain lain
 *     start {} / reset {}             — admin membuka barrier / mereset
 *     peer-left { from }              — pemain disconnect
 *
 * Jalankan lokal:  npm install  lalu  node server.js
 * Health check:    GET /  atau  GET /health  -> { ok:true, rooms:{...} }
 * Port diambil dari process.env.PORT (wajib untuk Render).
 */
'use strict';

const http = require('http');
const WebSocket = require('ws');

const PORT = parseInt(process.env.PORT || '3000', 10);
const ROOMS = ['map-sakura', 'map-salju', 'map-pantai'];

/* room -> Set<WebSocket> */
const rooms = new Map();
for (const r of ROOMS) rooms.set(r, new Set());

function roomStats() {
  const out = {};
  for (const [name, set] of rooms) out[name] = set.size;
  return out;
}

const server = http.createServer((req, res) => {
  if (req.url === '/' || req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, service: 'kaniku-multiplayer', rooms: roomStats() }));
    return;
  }
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

const wss = new WebSocket.Server({ server });

/* Kirim pesan ke semua pemain di room kecuali pengirim. */
function broadcast(room, sender, msg) {
  const set = rooms.get(room);
  if (!set) return;
  const data = JSON.stringify(msg);
  for (const client of set) {
    if (client !== sender && client.readyState === WebSocket.OPEN) {
      try { client.send(data); } catch (e) { /* abaikan */ }
    }
  }
}

/* Keluarkan pemain dari room + beri tahu pemain lain. */
function leaveRoom(ws) {
  if (!ws.room) return;
  const room = ws.room;
  const set = rooms.get(room);
  if (set) set.delete(ws);
  broadcast(room, ws, { type: 'peer-left', from: ws.id });
  console.log(`[leave] ${ws.name || '?'} (${ws.id}) keluar dari ${room} — isi room: ${set ? set.size : 0}`);
  ws.room = null;
}

wss.on('connection', (ws) => {
  ws.room = null;
  ws.id = null;
  ws.name = null;
  ws.avatar = 1;

  ws.on('message', (raw) => {
    let m;
    try { m = JSON.parse(raw.toString()); } catch (e) { return; }
    if (!m || typeof m.type !== 'string') return;

    if (m.type === 'hello') {
      const room = ROOMS.includes(m.room) ? m.room : 'map-sakura';
      /* Pindah room (bila hello kedua dengan room berbeda): keluar dari room lama. */
      if (ws.room && ws.room !== room) leaveRoom(ws);
      ws.room = room;
      ws.id = (typeof m.from === 'string' && m.from) ? String(m.from).slice(0, 32) : ('s' + Math.random().toString(36).slice(2, 10));
      ws.name = String(m.name || 'Pemain').slice(0, 16);
      ws.avatar = Number(m.avatar) || 1;
      rooms.get(room).add(ws);
      console.log(`[join] ${ws.name} (${ws.id}) masuk ${room} — isi room: ${rooms.get(room).size}`);
      /* Beri tahu pemain lain supaya mereka membalas dengan posisi. */
      broadcast(room, ws, { type: 'hello', from: ws.id, name: ws.name, avatar: ws.avatar });
      return;
    }

    if (!ws.room) return; /* harus hello dulu */

    if (m.type === 'pos' || m.type === 'start' || m.type === 'reset') {
      if (!m.from) m.from = ws.id;
      broadcast(ws.room, ws, m);
      return;
    }
    if (m.type === 'bye') leaveRoom(ws);
  });

  ws.on('close', () => leaveRoom(ws));
  ws.on('error', () => { /* diam */ });
});

server.listen(PORT, () => {
  console.log(`KANIKU multiplayer server jalan di port ${PORT}`);
  console.log(`Room: ${ROOMS.join(', ')}`);
});
