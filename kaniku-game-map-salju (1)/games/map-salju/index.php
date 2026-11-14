<?php
/**
 * KANIKU — Desa Salju (embedded).
 * Game di-embed di dalam website: hanya member yang login yang bisa membuka.
 * File game (game.html + 12 PNG) disajikan lewat PHP agar akses langsung
 * tanpa login tidak bisa. Nama pemain diambil dari akun member yang login
 * (window.KANIKU_PLAYER_NAME), jadi pemain tidak perlu mengisi nama lagi.
 */
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    flash('Masuk dulu sebagai member untuk bermain Desa Salju.', 'warn');
    redirect('../../login.php');
}
if (!is_member()) {
    $u = current_user();
    $msg = ($u && ($u['status'] ?? '') === 'pending')
        ? 'Akunmu masih menunggu persetujuan admin sebelum bisa bermain.'
        : 'Akunmu belum aktif. Hubungi admin ekskul.';
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Desa Salju — KANIKU</title>' .
        '<link rel="stylesheet" href="../../assets/css/style.css"></head><body>' .
        '<div class="auth-wrap"><div class="auth-card"><h1>Belum Bisa Bermain</h1>' .
        '<p class="auth-sub">' . e($msg) . '</p>' .
        '<a class="btn btn-plum btn-block" href="../../dashboard.php">Kembali ke Dashboard</a></div></div></body></html>';
    exit;
}

// Status event game: hanya dibuka saat admin memulai (admin selalu boleh masuk).
$gameEv = null;
foreach (load_json('events.json') as $ev) {
    if (($ev['type'] ?? '') === 'game') {
        $gameEv = $ev;
    }
}
if ($gameEv !== null && ($gameEv['status'] ?? '') !== 'active' && !is_admin()) {
    http_response_code(200);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Desa Salju — KANIKU</title>' .
        '<link rel="stylesheet" href="../../assets/css/style.css"></head><body>' .
        '<div class="auth-wrap"><div class="auth-card"><h1>Desa Salju Belum Dimulai</h1>' .
        '<p class="auth-sub">Game event Desa Salju belum dimulai. Menunggu admin menekan tombol Mulai.</p>' .
        '<a class="btn btn-plum btn-block" href="../../games.php">Kembali ke Games</a></div></div></body></html>';
    exit;
}

$gameFile = __DIR__ . '/game.html';
if (!file_exists($gameFile)) {
    // Pesan yang jelas (bukan sekadar teks polos): file game belum ada di
    // server — yang perlu dilakukan adalah meng-upload folder games/map-salju/
    // (khususnya game.html) ke hosting. Bukan salah login atau event.
    http_response_code(404);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>File Game Tidak Ditemukan — KANIKU</title>'
        . '<link rel="stylesheet" href="../../assets/css/style.css"></head><body>'
        . '<div class="auth-wrap"><div class="auth-card"><h1>File Game Tidak Ditemukan</h1>'
        . '<p class="auth-sub">File game (game.html) belum ada di server. Minta admin meng-upload folder <b>games/map-salju/</b> (khususnya file <b>game.html</b>) ke hosting, lalu coba lagi.</p>'
        . '<a class="btn btn-plum btn-block" href="../../games.php">Kembali ke Games</a></div></div></body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
// Suntik soal dari admin (data/questions.json), token CSRF, URL kirim skor,
// dan NAMA LENGKAP pemain dari akun member yang login ke dalam game sebelum
// disajikan — game memuat soal ini bila ada, kalau tidak memakai contoh
// hiragana bawaannya. Nama pemain otomatis, tidak perlu diisi manual.
$me = current_user();
$html = file_get_contents($gameFile);
$inject = '<script>'
    . 'window.KANIKU_QUESTIONS = ' . json_encode(game_questions()) . ';'
    . 'window.KANIKU_CSRF = ' . json_encode(csrf_token()) . ';'
    . 'window.KANIKU_SCORE_URL = ' . json_encode('../../games.php') . ';'
    . 'window.KANIKU_PLAYER_NAME = ' . json_encode($me['name'] ?? 'Pemain') . ';'
    . 'window.KANIKU_WS_URL = ' . json_encode(load_multiplayer_settings()['ws_url'] ?? '') . ';'
    . '</script>';
echo str_replace('</head>', $inject . '</head>', $html);
exit;
