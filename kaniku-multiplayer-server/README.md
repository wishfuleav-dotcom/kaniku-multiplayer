# KANIKU — Server Multiplayer (WebSocket)

Server live supaya murid di HP berbeda bisa saling lihat di map yang sama
(Map Sakura / Desa Salju / Map Pantai). Server ini hanya me-relay pesan posisi
antar pemain — skor tetap dikirim game ke `games.php` seperti biasa, dan tidak
ada data yang disimpan di server (tanpa database).

## Cara deploy ke Render (gratis)

1. **Buat akun Render** di https://render.com (gratis, bisa daftar pakai GitHub).
2. **Buat repo GitHub baru** (misalnya `kaniku-multiplayer`), lalu upload
   kelima file folder ini ke repo itu: `server.js`, `package.json`,
   `package-lock.json`, `render.yaml`, `README.md`.
   (Di GitHub: tombol **Add file → Upload files**, lalu **Commit changes**.)
3. **Di Render:** klik **New → Web Service** → pilih repo `kaniku-multiplayer`
   → Render membaca `render.yaml` otomatis (Build Command `npm install`,
   Start Command `node server.js`) → klik **Create Web Service**.
4. **Tunggu deploy selesai** (1–3 menit). Render memberi URL, misalnya:
   `https://kaniku-multiplayer.onrender.com`
5. **Ubah ke URL WebSocket:** ganti `https://` menjadi `wss://`:
   `wss://kaniku-multiplayer.onrender.com`
6. **Tempel URL itu di website KANIKU:** buka Panel Admin → tab **Soal & Map**
   → bagian **Multiplayer — Main Bareng Antar-HP** → isi kolom URL Server
   WebSocket → **Simpan URL Multiplayer**.
7. **Upload 3 ZIP game + patch website** ke `htdocs/` (JANGAN timpa `data/`).
8. **Uji dengan 2 HP:** buka game di kedua HP (login member masing-masing),
   masuk lobby — kedua pemain harus saling terlihat. Admin menekan **Mulai**
   di salah satu HP → barrier terbuka di kedua HP.

## Catatan penting — free tier Render

- **Server tidur setelah ~15 menit tidak ada yang memakai.** Saat ada yang
  membuka game, server bangun dulu — **koneksi pertama butuh ~30–60 detik**.
  Jadi pemain pertama yang masuk mungkin menunggu sebentar sebelum pemain lain
  muncul. Setelah bangun, koneksi langsung dan lancar.
- Kalau game terasa "sendirian" padahal teman sudah masuk, tunggu 30–60 detik
  lalu masuk lobby lagi (tutup halaman game lalu buka lagi).
- Cek server hidup atau tidak: buka `https://kaniku-multiplayer.onrender.com/health`
  di browser — harus tampil `{"ok":true,...}`. (Ganti nama host dengan URL Anda.)

## Menjalankan lokal (untuk tes)

```bash
npm install
node server.js
# lalu buka http://localhost:3000/health — harus tampil {"ok":true,...}
```

Untuk tes dengan game lokal, isi URL di Panel Admin dengan
`ws://localhost:3000` (hanya untuk tes di komputer sendiri — HP lain tidak
bisa mencapai localhost Anda).

## Protokol pesan (JSON)

- Client → server: `hello` { room, name, avatar }, `pos` { x, y, dir,
  moving, score, answered, state }, `start`, `reset`, `bye`.
- Server → client: `hello` { from, name, avatar }, `pos` { from, ... },
  `start`, `reset`, `peer-left` { from }.
- Room: `map-sakura`, `map-salju`, `map-pantai` (satu room per map).

## Bila multiplayer dimatikan

Kosongkan kolom URL di Panel Admin lalu simpan — game kembali single-player
(ditambah live antar-tab di perangkat yang sama via BroadcastChannel, seperti
sebelumnya). Game tidak error bila URL kosong atau server mati.
