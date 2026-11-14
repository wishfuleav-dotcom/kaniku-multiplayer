<?php
/**
 * KANIKU — Kaltim Nippon Club
 * Core helpers: JSON storage, session auth, CSRF, points, badges, notifications.
 * Pure PHP, no database. Data lives in data/*.json (protected by .htaccess).
 * Roles: guest (session only), member (Siswa), admin.
 * Member accounts start as "pending" — admin must approve before login.
 */

define('DATA_DIR', __DIR__ . '/../data');
define('UPLOAD_DIR', __DIR__ . '/../uploads');

// Semua tanggal/waktu situs memakai WIB (Asia/Jakarta) — Podium harian
// bergulir tepat pukul 00.00 WIB.
date_default_timezone_set('Asia/Jakarta');

// ---------- Points system (v3 — menggantikan sistem SRS lama) ----------
// Absensi Hadir +5; Upload Karya +5 (maks 1x/hari); Event Game:
// Top 1 +3, Top 2 +2, Top 3 +2, di luar top 3 +1.
define('PTS_ATTENDANCE', 5);
define('PTS_ARTWORK_UPLOAD', 5);
define('PTS_GAME_TOP1', 3);
define('PTS_GAME_TOP2', 2);
define('PTS_GAME_TOP3', 2);
define('PTS_GAME_OTHER', 1);

// ---------- Polyfill mbstring (untuk host tanpa ekstensi mbstring) ----------
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen($s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower($s); }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $enc = null) { return strtoupper($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($hay, $needle, $off = 0, $enc = null) { return strpos($hay, $needle, $off); }
}
if (!function_exists('mb_strrpos')) {
    function mb_strrpos($hay, $needle, $off = 0, $enc = null) { return strrpos($hay, $needle, $off); }
}

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------- JSON storage (with file locking) ----------
function load_json(string $file): array
{
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_json(string $file, array $data): bool
{
    $path = DATA_DIR . '/' . $file;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fp = fopen($path, 'c');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function load_settings(): array
{
    $s = load_json('settings.json');
    return array_merge([
        'site_name'       => 'KANIKU',
        'attendance_open' => true,
    ], $s);
}

// ---------- Escaping / formatting ----------
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 1200 -> 1.2k, 950 -> 950 */
function fmt_num(int $n): string
{
    if ($n >= 1000) {
        $v = round($n / 1000, 1);
        return rtrim(rtrim((string) $v, '0'), '.') . 'k';
    }
    return (string) $n;
}

function uid(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(6));
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

// ---------- Flash messages ----------
function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- Users & auth ----------
function find_user_by_id(string $id): ?array
{
    foreach (load_json('users.json') as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function find_user_by_username(string $username): ?array
{
    $needle = mb_strtolower(trim($username));
    foreach (load_json('users.json') as $u) {
        if (mb_strtolower(trim($u['username'] ?? '')) === $needle) {
            return $u;
        }
    }
    return null;
}

function find_user_by_name(string $name): ?array
{
    $needle = mb_strtolower(trim($name));
    foreach (load_json('users.json') as $u) {
        if (mb_strtolower(trim($u['name'])) === $needle) {
            return $u;
        }
    }
    return null;
}

/** Tingkatan Kelas: 10, 11, 12, 13. */
function kelas_options(): array
{
    return ['10', '11', '12', '13'];
}

/** Pilihan Jurusan (20): jurusan + nomor kelas. */
function jurusan_options(): array
{
    return [
        'DGM 1', 'DGM 2', 'ELIND 1', 'ELIND 2', 'MESIN 1', 'MESIN 2',
        'RPL 1', 'RPL 2', 'RPL 3', 'RPL 4', 'TKR 1', 'TKR 2',
        'LAS 1', 'LAS 2', 'OI 1', 'OI 2', 'SIJA 1', 'SIJA 2',
        'MEKA 1', 'MEKA 2',
    ];
}

/** Label "Kelas • Jurusan" untuk tampilan, mis. "11 • RPL 1". */
function kelas_jurusan(array $u): string
{
    $k = trim($u['kelas'] ?? '');
    $j = trim($u['jurusan'] ?? '');
    if ($k !== '' && $j !== '') {
        return $k . ' • ' . $j;
    }
    return $k !== '' ? $k : $j;
}

/** Statistik asli dari data (tanpa angka palsu, tanpa "+"). */
function site_stats(): array
{
    $users = load_json('users.json');
    $members = 0;
    $badges = 0;
    foreach ($users as $u) {
        if (($u['role'] ?? '') === 'member' && ($u['status'] ?? '') === 'approved') {
            $members++;
        }
        $badges += count($u['badges'] ?? []);
    }
    $arts = load_json('artworks.json');
    $approvedArts = 0;
    foreach ($arts as $a) {
        if (($a['status'] ?? '') === 'approved') {
            $approvedArts++;
        }
    }
    return [
        'members'  => $members,
        'events'   => count(load_json('events.json')),
        'games'    => count(load_json('game_scores.json')),
        'artworks' => $approvedArts,
        'badges'   => $badges,
    ];
}

/** Isi .card-media event: <img> bila ada poster, kalau tidak kartu dekoratif gradient.
 *  Bila gambar poster gagal dimuat (mis. file hilang di server), otomatis
 *  diganti kartu dekoratif — tidak pernah tampil sebagai broken image. */
function event_poster_html(array $ev): string
{
    $poster = trim($ev['poster'] ?? '');
    $title = e($ev['title'] ?? 'Event');
    $decorSvg = '<svg width="54" height="54" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 5c3-1.5 6-1.5 10 0s7 1.5 10 0"/><path d="M5 8h14"/><path d="M8 8v13M16 8v13"/><path d="M4 21h16"/></svg>';
    $decor = '<div class="poster-decor" role="img" aria-label="' . $title . '">' . $decorSvg . '<span>' . $title . '</span></div>';
    if ($poster !== '' && file_exists(__DIR__ . '/../' . $poster)) {
        $hiddenDecor = '<div class="poster-decor" role="img" aria-label="' . $title . '" style="display:none">' . $decorSvg . '<span>' . $title . '</span></div>';
        return '<img src="' . e(img_url($poster)) . '" alt="' . $title . '" loading="lazy" onerror="this.style.display=\'none\';var d=this.nextElementSibling;if(d){d.style.display=\'flex\';}">' . $hiddenDecor;
    }
    return $decor;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return find_user_by_id($_SESSION['user_id']);
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_guest(): bool
{
    return !is_logged_in() && !empty($_SESSION['guest']);
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/** Approved member (not pending/rejected/inactive). Admins pass too. */
function is_member(): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    if (($u['role'] ?? '') === 'admin') {
        return true;
    }
    return ($u['role'] ?? '') === 'member' && ($u['status'] ?? '') === 'approved';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('Silakan masuk dulu untuk mengakses halaman ini.', 'warn');
        redirect('login.php');
    }
    $u = current_user();
    if (!empty($u['must_change_password'])) {
        $here = basename($_SERVER['PHP_SELF'] ?? '');
        if ($here !== 'changepassword.php' && $here !== 'logout.php') {
            redirect('changepassword.php');
        }
    }
}

/** Member dashboard / member-only features. Admins allowed. */
function require_member(): void
{
    require_login();
    if (!is_member()) {
        $u = current_user();
        if ($u && ($u['status'] ?? '') === 'pending') {
            flash('Akunmu masih menunggu persetujuan admin. Mohon tunggu ya!', 'warn');
        } else {
            flash('Akunmu belum aktif. Hubungi admin ekskul.', 'error');
        }
        redirect('index.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('Halaman ini hanya untuk admin.', 'error');
        redirect('index.php');
    }
}

// ---------- Points, badges, notifications ----------
/** Tanggal hari ini (WIB, Y-m-d). */
function wib_today(): string
{
    return date('Y-m-d');
}

/**
 * Beri poin: tambah ke total seumur hidup (users.json) DAN catat ke
 * points.json per tanggal (WIB) agar Podium harian bisa dihitung.
 */
function award_points(string $user_id, int $points, string $reason = ''): void
{
    $users = load_json('users.json');
    foreach ($users as &$u) {
        if ($u['id'] === $user_id) {
            $u['points'] = (int) ($u['points'] ?? 0) + $points;
            break;
        }
    }
    unset($u);
    save_json('users.json', $users);
    $log = load_json('points.json');
    $log[] = [
        'id' => uid('pts_'),
        'user_id' => $user_id,
        'points' => $points,
        'reason' => $reason,
        'date' => wib_today(),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    save_json('points.json', $log);
    if ($reason !== '') {
        notify($user_id, 'points', $reason . ' (+' . $points . ' poin)');
    }
}

/**
 * Leaderboard KUMULATIF (total poin seumur hidup, bukan harian).
 * Total per user = seluruh catatan points.json (semua tanggal). Bila total
 * seumur hidup di users.json lebih besar (mis. catatan lama pernah terhapus
 * oleh reset di versi sebelumnya), dipakai yang lebih besar supaya poin
 * member tidak hilang. Peringkat dihitung ulang setiap kali halaman
 * dimuat, jadi selalu mutakhir: poin yang bertambah ikut terhitung pada
 * pembaruan hari berikutnya (pergantian hari WIB 00.00) — dan leaderboard
 * TIDAK PERNAH di-reset. Member yang disetujui saja.
 */
function cumulative_leaderboard(int $limit = 10): array
{
    $totals = [];
    foreach (load_json('points.json') as $p) {
        $uid = $p['user_id'] ?? '';
        if ($uid !== '') {
            $totals[$uid] = ($totals[$uid] ?? 0) + (int) ($p['points'] ?? 0);
        }
    }
    $rows = [];
    foreach (load_json('users.json') as $u) {
        if (($u['role'] ?? '') === 'member' && ($u['status'] ?? '') === 'approved') {
            $total = max($totals[$u['id']] ?? 0, (int) ($u['points'] ?? 0));
            $rows[] = array_merge($u, ['points' => $total]);
        }
    }
    usort($rows, function ($a, $b) {
        $d = (int) ($b['points'] ?? 0) <=> (int) ($a['points'] ?? 0);
        return $d !== 0 ? $d : strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? ''));
    });
    return array_slice($rows, 0, $limit);
}

/** Podium: 3 besar leaderboard kumulatif (hanya yang punya poin). */
function podium_top3(): array
{
    $top = cumulative_leaderboard(3);
    return array_values(array_filter($top, function ($u) {
        return (int) ($u['points'] ?? 0) > 0;
    }));
}

/** Apakah user sudah mendapat poin Upload Karya hari ini (maks 1x/hari). */
function artwork_points_awarded_today(string $user_id, string $date = null): bool
{
    $date = $date ?? wib_today();
    foreach (load_json('points.json') as $p) {
        if (($p['user_id'] ?? '') === $user_id && ($p['date'] ?? '') === $date
            && strpos($p['reason'] ?? '', 'Upload karya') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Poin Event Game: dari skor Event Game yang tersimpan (game_scores.json,
 * game = map aktif: 'map-sakura' atau 'map-salju'), peringkat berdasarkan
 * skor terbaik tiap member.
 * Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1. Dijalankan sekali per event
 * (ditandai di events.json: points_awarded = true).
 * Mengembalikan jumlah member yang diberi poin.
 */
function award_event_game_points(string $event_id): int
{
    $events = load_json('events.json');
    $evIdx = null;
    foreach ($events as $i => $ev) {
        if ($ev['id'] === $event_id) {
            $evIdx = $i;
            break;
        }
    }
    if ($evIdx === null || !empty($events[$evIdx]['points_awarded'])) {
        return 0;
    }
    $best = [];
    foreach (load_json('game_scores.json') as $s) {
        if (!in_array($s['game'] ?? '', ['map-sakura', 'map-salju', 'map-pantai'], true)) {
            continue;
        }
        $uid = $s['user_id'] ?? '';
        $best[$uid] = max($best[$uid] ?? 0, (int) ($s['score'] ?? 0));
    }
    if (empty($best)) {
        $events[$evIdx]['points_awarded'] = true;
        save_json('events.json', $events);
        return 0;
    }
    arsort($best);
    $rank = 0;
    $awarded = 0;
    $mapName = active_map_meta()['name'];
    foreach ($best as $uid => $score) {
        $rank++;
        $u = find_user_by_id($uid);
        if ($u === null || ($u['role'] ?? '') !== 'member' || ($u['status'] ?? '') !== 'approved') {
            continue;
        }
        $pts = $rank === 1 ? PTS_GAME_TOP1 : ($rank === 2 ? PTS_GAME_TOP2 : ($rank === 3 ? PTS_GAME_TOP3 : PTS_GAME_OTHER));
        award_points($uid, $pts, 'Event Game ' . $mapName . ' — peringkat ' . $rank . ' (skor ' . $score . ')');
        if ($rank <= 3) {
            notify($uid, 'points', 'Kamu peringkat ' . $rank . ' di Event Game ' . $mapName . '! +' . $pts . ' poin.');
        }
        $awarded++;
    }
    $events[$evIdx]['points_awarded'] = true;
    save_json('events.json', $events);
    return $awarded;
}

/** Path web absolut dari root domain (diawali "/") — benar di semua halaman,
 *  tidak peduli seberapa dalam halaman itu (mis. games/map-sakura/). */
function web_path(string $rel): string
{
    return '/' . ltrim($rel, '/');
}

/** URL gambar upload lewat endpoint img.php (absolut dari root) — gambar
 *  disajikan oleh PHP, jadi tidak bergantung pada file-serving server
 *  (.htaccess). Path di luar uploads/ (mis. aset) tetap memakai web_path(). */
function img_url(string $rel): string
{
    $rel = ltrim($rel, '/');
    if (strpos($rel, 'uploads/') === 0) {
        return '/img.php?f=' . rawurlencode($rel);
    }
    return web_path($rel);
}

/** URL avatar member: foto profil bila ada, kalau tidak gambar default (kucing putih). */
function avatar_url(?array $u): string
{
    $photo = trim($u['photo'] ?? '');
    if ($photo !== '' && file_exists(__DIR__ . '/../' . $photo)) {
        return img_url($photo);
    }
    return web_path('assets/avatar-default.jpg');
}

/** Tag <img> avatar lingkaran untuk member. Bila foto gagal dimuat
 *  (mis. file hilang di server), langsung ganti avatar default —
 *  tidak pernah tampil sebagai broken image. */
function avatar_img(?array $u, int $size = 38, string $class = ''): string
{
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    $fallback = web_path('assets/avatar-default.jpg');
    return '<img src="' . e(avatar_url($u)) . '" alt="' . e($u['name'] ?? 'Member') . '" width="' . $size . '" height="' . $size . '"' . $cls . ' style="border-radius:50%;object-fit:cover" onerror="this.onerror=null;this.src=\'' . e($fallback) . '\'">';
}

// ---------- Pertemuan & token absensi ----------
function load_meetings(): array
{
    $m = load_json('meetings.json');
    usort($m, function ($a, $b) { return strcmp($a['date'] ?? '', $b['date'] ?? ''); });
    return $m;
}

function load_tokens(): array
{
    return load_json('tokens.json');
}

/** Cari token aktif berdasarkan kode (tidak case-sensitive). */
function find_active_token(string $code): ?array
{
    $code = mb_strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    foreach (load_tokens() as $t) {
        if (mb_strtoupper(trim($t['code'] ?? '')) === $code && !empty($t['active'])) {
            return $t;
        }
    }
    return null;
}

function find_meeting(string $id): ?array
{
    foreach (load_meetings() as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

/**
 * Check-in absensi dengan token. Mengembalikan [sukses, pesan].
 * Token menentukan pertemuan (tanggal absen = tanggal pertemuan).
 */
function do_checkin(array $user, string $tokenCode): array
{
    $token = find_active_token($tokenCode);
    if ($token === null) {
        return [false, 'Token absensi tidak valid atau sudah tidak aktif. Minta token ke admin.'];
    }
    $meeting = find_meeting($token['meeting_id'] ?? '');
    if ($meeting === null) {
        return [false, 'Pertemuan untuk token ini tidak ditemukan. Hubungi admin.'];
    }
    $date = $meeting['date'];
    $att = load_json('attendance.json');
    foreach ($att as $a) {
        if (($a['user_id'] ?? '') === $user['id']
            && (($a['meeting_id'] ?? '') === $meeting['id'] || ($a['date'] ?? '') === $date)) {
            return [false, 'Kamu sudah absen untuk pertemuan ' . $date . '.'];
        }
    }
    $att[] = [
        'id' => uid('att_'),
        'user_id' => $user['id'],
        'name' => $user['name'],
        'kelas' => $user['kelas'],
        'jurusan' => $user['jurusan'] ?? '',
        'date' => $date,
        'time' => date('H:i:s'),
        'meeting_id' => $meeting['id'],
        'token' => $token['code'],
        'created_at' => date('Y-m-d H:i:s'),
    ];
    save_json('attendance.json', $att);
    award_points($user['id'], PTS_ATTENDANCE, 'Absensi ' . $date . ' (' . ($meeting['title'] ?? 'Pertemuan') . ')');
    check_badges(current_user());
    return [true, 'Check-in berhasil! +' . PTS_ATTENDANCE . ' poin.'];
}

// ---------- Soal & map game ----------
/** 15 soal contoh gerbang Map Sakura (hiragana) — dipakai bila soal admin kosong. */
function default_questions(): array
{
    $pairs = [
        ['あ', 'a', ['a', 'i', 'u', 'ka']],
        ['き', 'ki', ['sa', 'ki', 'chi', 'ni']],
        ['す', 'su', ['su', 'tsu', 'mu', 'nu']],
        ['ね', 'ne', ['ne', 're', 'wa', 'me']],
        ['は', 'ha', ['ha', 'ho', 'he', 'na']],
        ['ゆ', 'yu', ['yu', 'yo', 'ya', 'u']],
        ['ら', 'ra', ['ra', 'ro', 're', 'wa']],
        ['を', 'wo', ['wo', 'o', 'to', 'no']],
        ['ん', 'n', ['n', 'mu', 'so', 'tsu']],
        ['と', 'to', ['to', 'ta', 'te', 'chi']],
        ['ち', 'chi', ['chi', 'sa', 'ni', 'ta']],
        ['ぬ', 'nu', ['nu', 'me', 'ne', 'mu']],
        ['ほ', 'ho', ['ho', 'ha', 'he', 'mo']],
        ['せ', 'se', ['se', 'sa', 'su', 'te']],
        ['む', 'mu', ['mu', 'nu', 'mo', 'me']],
    ];
    $out = [];
    foreach ($pairs as $i => $p) {
        $out[] = ['n' => $i + 1, 'type' => 'mc', 'prompt' => 'Huruf hiragana ini dibaca…', 'display' => $p[0], 'img' => '', 'options' => $p[2], 'answer' => $p[1]];
    }
    return $out;
}

/** 15 soal gerbang Map Sakura (format admin); bila file belum ada pakai contoh hiragana. */
function load_questions(): array
{
    $qs = load_json('questions.json');
    if (count($qs) >= 1) {
        return array_slice($qs, 0, 15);
    }
    return default_questions();
}

/** Soal dalam format yang dimengerti game Map Sakura. */
function game_questions(): array
{
    $out = [];
    foreach (load_questions() as $i => $q) {
        $type = ($q['type'] ?? 'mc') === 'essay' ? 'essay' : 'mc';
        $out[] = [
            'k' => $q['display'] ?? '',
            'a' => $q['answer'] ?? '',
            'o' => $type === 'mc' ? array_values(array_slice($q['options'] ?? [], 0, 4)) : [],
            'type' => $type,
            'img' => !empty($q['img']) ? img_url($q['img']) : '',
            'prompt' => $q['prompt'] ?? 'Jawab pertanyaan ini',
        ];
    }
    return $out;
}

/** Daftar map game yang tersedia (key => meta). Map Sakura tetap default. */
function game_maps(): array
{
    return [
        'map-sakura' => ['name' => 'Map Sakura', 'theme' => 'Taman Sakura', 'img' => 'assets/game-map-sakura.png'],
        'map-salju'  => ['name' => 'Desa Salju', 'theme' => 'Desa Salju (Shirakawa-go)', 'img' => 'assets/game-map-salju.webp'],
        'map-pantai' => ['name' => 'Map Pantai', 'theme' => 'Pantai Okinawa', 'img' => 'assets/game-map-pantai.webp'],
    ];
}

/** Key map aktif (disimpan di data/map_settings.json, field active_map). */
function active_map_key(): string
{
    $maps = game_maps();
    $key = load_map_settings()['active_map'] ?? 'map-sakura';
    return isset($maps[$key]) ? $key : 'map-sakura';
}

/** Meta map aktif (nama, tema, gambar kartu). */
function active_map_meta(): array
{
    return game_maps()[active_map_key()];
}

/** Pengaturan map aktif (nama + tema) yang ditampilkan di halaman game. */
function load_map_settings(): array
{
    return array_merge(['name' => 'Map Sakura', 'theme' => 'Taman Sakura', 'active_map' => 'map-sakura'], load_json('map_settings.json'));
}

/** Pengaturan multiplayer (URL server WebSocket), disimpan di data/multiplayer.json.
 *  Kosong = multiplayer nonaktif, game jalan single-player. */
function load_multiplayer_settings(): array
{
    return array_merge(['ws_url' => ''], load_json('multiplayer.json'));
}

function notify($user_id, string $type, string $message, string $link = ''): void
{
    $list = load_json('notifications.json');
    $list[] = [
        'id'         => uid('ntf_'),
        'user_id'    => $user_id, // user id, or 'all' for every member
        'type'       => $type,
        'message'    => $message,
        'link'       => $link,
        'created_at' => date('Y-m-d H:i:s'),
        'read_by'    => [],
    ];
    save_json('notifications.json', $list);
}

function user_notifications(string $user_id, int $limit = 20): array
{
    $all = load_json('notifications.json');
    $mine = array_filter($all, function ($n) use ($user_id) {
        return $n['user_id'] === 'all' || $n['user_id'] === $user_id;
    });
    usort($mine, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
    return array_slice($mine, 0, $limit);
}

function unread_count(string $user_id): int
{
    $c = 0;
    foreach (user_notifications($user_id, 100) as $n) {
        if (!in_array($user_id, $n['read_by'] ?? [], true)) {
            $c++;
        }
    }
    return $c;
}

/** Badge definitions: id => [label, icon(emoji-free key), description] */
function badge_defs(): array
{
    return [
        'perfect'     => ['Perfect Attendance', 'Kehadiran sempurna — hadir 5 kali dalam satu bulan.'],
        'master'      => ['Japanese Master', 'Menang kuis bahasa Jepang dengan skor minimal 80%.'],
        'artist'      => ['Artist', 'Memiliki karya yang disetujui di Hall of Fame.'],
        'champion'    => ['Game Champion', 'Menang 3 kali di Mini Games.'],
        'active'      => ['Active Member', 'Mengumpulkan 50 poin atau lebih.'],
        'contributor' => ['Top Contributor', 'Masuk 3 besar Leaderboard.'],
    ];
}

/** Check & award newly earned badges for a user. Returns new badge ids. */
function check_badges(array $user): array
{
    $uid = $user['id'] ?? '';
    if ($uid === '') {
        return [];
    }
    $have = $user['badges'] ?? [];
    $new = [];

    $att = load_json('attendance.json');
    $month = date('Y-m');
    $attThisMonth = 0;
    $attTotal = 0;
    foreach ($att as $a) {
        if (($a['user_id'] ?? '') === $uid) {
            $attTotal++;
            if (strpos($a['date'] ?? '', $month) === 0) {
                $attThisMonth++;
            }
        }
    }

    $scores = load_json('game_scores.json');
    $wins = 0;
    $bestQuiz = 0;
    foreach ($scores as $s) {
        if (($s['user_id'] ?? '') === $uid && !empty($s['win'])) {
            $wins++;
            $bestQuiz = max($bestQuiz, (int) ($s['score'] ?? 0));
        }
    }

    $arts = load_json('artworks.json');
    $approvedArts = 0;
    foreach ($arts as $a) {
        if (($a['author_id'] ?? '') === $uid && ($a['status'] ?? '') === 'approved') {
            $approvedArts++;
        }
    }

    $points = (int) ($user['points'] ?? 0);

    $lb = leaderboard(3);
    $top3 = false;
    foreach ($lb as $row) {
        if ($row['id'] === $uid) {
            $top3 = true;
        }
    }

    $earn = [
        'perfect'     => $attThisMonth >= 5,
        'master'      => $bestQuiz >= 80,
        'artist'      => $approvedArts >= 1,
        'champion'    => $wins >= 3,
        'active'      => $points >= 50,
        'contributor' => $top3,
    ];

    foreach ($earn as $bid => $ok) {
        if ($ok && !in_array($bid, $have, true)) {
            $have[] = $bid;
            $new[] = $bid;
        }
    }

    if (!empty($new)) {
        $users = load_json('users.json');
        foreach ($users as &$u) {
            if ($u['id'] === $uid) {
                $u['badges'] = $have;
                break;
            }
        }
        unset($u);
        save_json('users.json', $users);
        $defs = badge_defs();
        foreach ($new as $bid) {
            notify($uid, 'badge', 'Badge baru: ' . $defs[$bid][0] . '!');
        }
    }

    return $new;
}

/** Leaderboard: ranking KUMULATIF (total poin seumur hidup) — member yang disetujui saja. Tidak pernah di-reset. */
function leaderboard(int $limit = 10): array
{
    return cumulative_leaderboard($limit);
}

// ---------- Image upload ----------
/**
 * Validate + move an uploaded image. Returns [path|null, error|null].
 * $field = $_FILES key, $subdir dipakai sebagai PREFIX nama file
 * (mis. 'artworks', 'profiles') — BUKAN subfolder.
 * File disimpan LANGSUNG di uploads/ (datar), mengikuti versi lama yang
 * terbukti jalan di InfinityFree: menyimpan ke subfolder (uploads/profiles/,
 * uploads/posters/, ...) gagal karena server tidak bisa membuat subfolder
 * tersebut, sehingga file tidak pernah tersimpan. Path yang dikembalikan
 * SELALU sudah diverifikasi ada di disk (bukan hanya record yang
 * ter-update) — tidak ada kegagalan diam-diam. Path lama yang masih
 * menunjuk ke subfolder (mis. uploads/profiles/xxx.jpg) tetap bisa tampil
 * bila file-nya masih ada, karena penampil gambar memakai file_exists.
 */
function upload_image(string $field, string $subdir, ?int $maxBytes = 2097152): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null]; // no file uploaded — allowed for optional fields
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, upload_error_message((int)$f['error'], $maxBytes)];
    }
    if ($maxBytes !== null && $f['size'] > $maxBytes) {
        return [null, 'Ukuran gambar maksimal ' . rtrim(rtrim(number_format($maxBytes / 1048576, 1), '0'), '.') . ' MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = null;
    if ($info !== false) {
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        $ext = $allowed[$info[2]] ?? null;
    }
    if ($ext === null) {
        // Fallback tanpa GD: cek magic bytes (WebP butuh GD di getimagesize).
        $head = @file_get_contents($f['tmp_name'], false, null, 0, 12);
        if ($head !== false) {
            if (substr($head, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a") {
                $ext = 'png';
            } elseif (substr($head, 0, 3) === "\xFF\xD8\xFF") {
                $ext = 'jpg';
            } elseif (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') {
                $ext = 'gif';
            } elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
                $ext = 'webp';
            }
        }
    }
    if ($ext === null) {
        return [null, 'File harus berupa gambar (JPG, PNG, WebP, atau GIF).'];
    }
    // Simpan LANGSUNG di uploads/ (datar) — jangan di subfolder.
    // (Alasan: subfolder uploads/profiles/, uploads/posters/, dll. tidak
    // bisa dibuat di InfinityFree, sehingga upload selalu gagal.)
    $dir = UPLOAD_DIR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) || !is_dir($dir)) {
            return [null, 'Folder upload tidak bisa dibuat di server. Hubungi admin.'];
        }
    }
    if (!is_writable($dir)) {
        return [null, 'Folder upload tidak bisa ditulis di server. Hubungi admin.'];
    }
    $name = $subdir . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return [null, 'Gagal menyimpan gambar di server. Coba lagi.'];
    }
    // Verifikasi: file benar-benar ada di disk dan tidak kosong.
    if (!file_exists($dest) || filesize($dest) <= 0) {
        return [null, 'Gambar gagal tersimpan di server. Coba lagi.'];
    }
    return ['uploads/' . $name, null];
}

/** Pesan error upload yang jelas per kode error PHP.
 *  $maxBytes = batas aplikasi (null = tanpa batas aplikasi; batas server
 *  tetap berlaku dan pesannya tidak menyebut angka MB). */
function upload_error_message(int $code, ?int $maxBytes = 2097152): string
{
    $tooBig = $maxBytes !== null
        ? 'File terlalu besar (melebihi batas server). Coba gambar yang lebih kecil (maks ' . rtrim(rtrim(number_format($maxBytes / 1048576, 1), '0'), '.') . ' MB).'
        : 'File terlalu besar (melebihi batas server). Coba gambar yang lebih kecil.';
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $tooBig;
        case UPLOAD_ERR_PARTIAL:
            return 'Upload tidak lengkap (koneksi terputus). Coba lagi.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server tidak bisa menyimpan file upload. Hubungi admin.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload diblokir oleh server. Coba lagi.';
        default:
            return 'Gagal mengunggah file. Coba lagi.';
    }
}

/** Parse nilai ukuran php.ini ("8M", "512K", "1G") menjadi byte. */
function parse_size_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $num = (float)$v;
    switch ($unit) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

/** True bila body POST dibuang PHP karena melebihi post_max_size:
 *  request POST berisi data, tapi $_POST & $_FILES kosong semua.
 *  Dalam kondisi ini halaman tidak boleh diam-diam mengabaikan kiriman. */
function post_too_large(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    if (!empty($_POST) || !empty($_FILES)) {
        return false;
    }
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) {
        return false;
    }
    $limit = parse_size_bytes((string)ini_get('post_max_size'));
    return $limit <= 0 || $len > $limit;
}

/**
 * Crop foto profil ke persegi 512x512 sesuai area yang dipilih user
 * (koordinat piksel asli: $x, $y, $size). Memakai GD bila tersedia;
 * bila GD tidak ada atau crop gagal, kembalikan false dan file asli
 * tetap dipakai (tampil lingkaran via object-fit:cover).
 */
function crop_profile_photo(string $relPath, float $x, float $y, float $size): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    $abs = __DIR__ . '/../' . ltrim($relPath, '/');
    if (!file_exists($abs)) {
        return false;
    }
    $info = @getimagesize($abs);
    if ($info === false) {
        return false;
    }
    [$w, $h, $type] = $info;
    $x = max(0, (int)round($x));
    $y = max(0, (int)round($y));
    $size = (int)round($size);
    $size = max(1, min($size, $w - $x, $h - $y));
    if ($size < 10) {
        return false;
    }
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($abs);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($abs);
            break;
        case IMAGETYPE_WEBP:
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false;
            break;
        case IMAGETYPE_GIF:
            $src = @imagecreatefromgif($abs);
            break;
        default:
            return false;
    }
    if ($src === false) {
        return false;
    }
    $dst = imagecreatetruecolor(512, 512);
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, $x, $y, 512, 512, $size, $size);
    $ok = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $ok = @imagejpeg($dst, $abs, 88);
            break;
        case IMAGETYPE_PNG:
            $ok = @imagepng($dst, $abs, 6);
            break;
        case IMAGETYPE_WEBP:
            $ok = function_exists('imagewebp') ? @imagewebp($dst, $abs, 88) : false;
            break;
        case IMAGETYPE_GIF:
            $ok = @imagegif($dst, $abs);
            break;
    }
    imagedestroy($src);
    imagedestroy($dst);
    return (bool)$ok;
}

/** Stats for the member dashboard profile card. */
function member_stats(string $user_id): array
{
    $att = load_json('attendance.json');
    $total = 0;
    foreach ($att as $a) {
        if (($a['user_id'] ?? '') === $user_id) {
            $total++;
        }
    }
    $events = load_json('events.json');
    $joined = 0;
    foreach ($events as $ev) {
        if (in_array($user_id, $ev['joins'] ?? [], true)) {
            $joined++;
        }
    }
    $arts = load_json('artworks.json');
    $uploads = 0;
    foreach ($arts as $a) {
        if (($a['author_id'] ?? '') === $user_id) {
            $uploads++;
        }
    }
    return ['attendance' => $total, 'events' => $joined, 'artworks' => $uploads];
}
