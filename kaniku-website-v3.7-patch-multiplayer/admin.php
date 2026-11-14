<?php
/** KANIKU — Dashboard Admin. */
$page_title = 'Dashboard Admin — KANIKU';
$active_nav = '';
require_once __DIR__ . '/includes/functions.php';
require_admin();

$user = current_user();
$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'members', 'attendance', 'events', 'games', 'questions', 'hof', 'announcements', 'calendar', 'stats'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'overview';
}

// ---------- Actions ----------
// Bila body POST dibuang server karena melebihi post_max_size,
// jangan diam-diam mengabaikan kiriman — beri pesan yang jelas.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_too_large()) {
    flash('Data yang dikirim terlalu besar (melebihi batas server). Coba foto/poster yang lebih kecil (maks 2 MB).', 'error');
    redirect('admin.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    // --- Members ---
    if ($action === 'member_approve' || $action === 'member_reject') {
        $users = load_json('users.json');
        foreach ($users as &$u) {
            if ($u['id'] === ($_POST['user_id'] ?? '')) {
                $u['status'] = $action === 'member_approve' ? 'approved' : 'rejected';
                $u['approved_at'] = date('Y-m-d H:i:s');
                notify($u['id'], 'member', $action === 'member_approve'
                    ? 'Akunmu sudah disetujui admin! Sekarang kamu bisa masuk.'
                    : 'Pendaftaranmu ditolak admin. Hubungi admin ekskul untuk info lebih lanjut.');
                flash('Akun ' . $u['name'] . ' ' . ($action === 'member_approve' ? 'disetujui.' : 'ditolak.'), 'success');
                break;
            }
        }
        unset($u);
        save_json('users.json', $users);
        redirect('admin.php?tab=members');
    }
    if ($action === 'member_toggle') {
        $users = load_json('users.json');
        foreach ($users as &$u) {
            if ($u['id'] === ($_POST['user_id'] ?? '') && ($u['role'] ?? '') !== 'admin') {
                $u['status'] = ($u['status'] ?? '') === 'inactive' ? 'approved' : 'inactive';
                flash('Status ' . $u['name'] . ' diubah menjadi ' . $u['status'] . '.', 'success');
                break;
            }
        }
        unset($u);
        save_json('users.json', $users);
        redirect('admin.php?tab=members');
    }
    if ($action === 'member_delete') {
        $users = load_json('users.json');
        $users = array_values(array_filter($users, function ($u) {
            return !($u['id'] === ($_POST['user_id'] ?? '') && ($u['role'] ?? '') !== 'admin');
        }));
        save_json('users.json', $users);
        flash('Member dihapus.', 'success');
        redirect('admin.php?tab=members');
    }
    if ($action === 'member_reset') {
        $new = $_POST['new_password'] ?? '';
        if (mb_strlen($new) < 8) {
            flash('Password baru minimal 8 karakter.', 'error');
        } else {
            $users = load_json('users.json');
            foreach ($users as &$u) {
                if ($u['id'] === ($_POST['user_id'] ?? '')) {
                    $u['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    $u['must_change_password'] = false;
                    flash('Password ' . $u['name'] . ' berhasil direset.', 'success');
                    break;
                }
            }
            unset($u);
            save_json('users.json', $users);
        }
        redirect('admin.php?tab=members');
    }
    if ($action === 'member_edit') {
        $users = load_json('users.json');
        foreach ($users as &$u) {
            if ($u['id'] === ($_POST['user_id'] ?? '')) {
                $u['name'] = trim($_POST['name'] ?? $u['name']);
                $nk = trim($_POST['kelas'] ?? '');
                if (in_array($nk, kelas_options(), true)) {
                    $u['kelas'] = $nk;
                }
                $nj = trim($_POST['jurusan'] ?? '');
                if (in_array($nj, jurusan_options(), true)) {
                    $u['jurusan'] = $nj;
                }
                unset($u['nis']);
                $u['username'] = trim($_POST['username'] ?? ($u['username'] ?? ''));
                // Guard: tidak boleh menurunkan diri sendiri atau admin terakhir.
                $newRole = in_array($_POST['role'] ?? '', ['member', 'admin'], true) ? $_POST['role'] : $u['role'];
                if (($u['role'] ?? '') === 'admin' && $newRole === 'member') {
                    $adminCount = 0;
                    foreach ($users as $x) {
                        if (($x['role'] ?? '') === 'admin') {
                            $adminCount++;
                        }
                    }
                    if ($u['id'] === $user['id']) {
                        $newRole = 'admin';
                        flash('Kamu tidak bisa menurunkan role akunmu sendiri.', 'error');
                    } elseif ($adminCount <= 1) {
                        $newRole = 'admin';
                        flash('Tidak bisa menurunkan admin terakhir. Harus ada minimal 1 admin.', 'error');
                    }
                }
                $u['role'] = $newRole;
                $u['status'] = in_array($_POST['status'] ?? '', ['pending', 'approved', 'rejected', 'inactive'], true) ? $_POST['status'] : $u['status'];
                flash('Data ' . $u['name'] . ' diperbarui.', 'success');
                break;
            }
        }
        unset($u);
        save_json('users.json', $users);
        redirect('admin.php?tab=members');
    }
    // Jadikan Admin / Jadikan Member (tombol cepat kelola user).
    if ($action === 'member_promote' || $action === 'member_demote') {
        $users = load_json('users.json');
        foreach ($users as &$u) {
            if ($u['id'] === ($_POST['user_id'] ?? '')) {
                if ($action === 'member_promote') {
                    $u['role'] = 'admin';
                    $u['status'] = 'approved';
                    notify($u['id'], 'member', 'Kamu sekarang menjadi Admin KANIKU.');
                    flash($u['name'] . ' sekarang menjadi Admin. (Admin baru tidak masuk tabel absensi.)', 'success');
                } else {
                    $adminCount = 0;
                    foreach ($users as $x) {
                        if (($x['role'] ?? '') === 'admin') {
                            $adminCount++;
                        }
                    }
                    if ($u['id'] === $user['id']) {
                        flash('Kamu tidak bisa menurunkan role akunmu sendiri.', 'error');
                    } elseif ($adminCount <= 1) {
                        flash('Tidak bisa menurunkan admin terakhir. Harus ada minimal 1 admin.', 'error');
                    } else {
                        $u['role'] = 'member';
                        notify($u['id'], 'member', 'Role kamu diubah menjadi Member KANIKU.');
                        flash($u['name'] . ' sekarang menjadi Member.', 'success');
                    }
                }
                break;
            }
        }
        unset($u);
        save_json('users.json', $users);
        redirect('admin.php?tab=members');
    }

    // --- Notifikasi admin ---
    if ($action === 'mark_all_read') {
        $list = load_json('notifications.json');
        foreach ($list as &$n) {
            if (($n['user_id'] === 'all' || $n['user_id'] === $user['id']) && !in_array($user['id'], $n['read_by'] ?? [], true)) {
                $n['read_by'][] = $user['id'];
            }
        }
        unset($n);
        save_json('notifications.json', $list);
        redirect('admin.php?tab=overview#notif-admin');
    }

    // --- Attendance ---
    if ($action === 'att_toggle') {
        $s = load_settings();
        $s['attendance_open'] = empty($s['attendance_open']);
        save_json('settings.json', $s);
        flash('Absensi ' . ($s['attendance_open'] ? 'dibuka.' : 'ditutup.'), 'success');
        redirect('admin.php?tab=attendance');
    }
    if ($action === 'att_delete') {
        $att = load_json('attendance.json');
        $att = array_values(array_filter($att, function ($a) {
            return $a['id'] !== ($_POST['att_id'] ?? '');
        }));
        save_json('attendance.json', $att);
        flash('Catatan kehadiran dihapus.', 'success');
        redirect('admin.php?tab=attendance');
    }
    // --- Pertemuan & token absensi ---
    if ($action === 'meeting_create') {
        $d = trim($_POST['date'] ?? '');
        $t = trim($_POST['title'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            flash('Tanggal pertemuan tidak valid.', 'error');
        } else {
            $meetings = load_meetings();
            $exists = false;
            foreach ($meetings as $m) {
                if (($m['date'] ?? '') === $d) {
                    $exists = true;
                }
            }
            if ($exists) {
                flash('Pertemuan tanggal ' . $d . ' sudah ada.', 'error');
            } else {
                $meetings[] = ['id' => uid('mtg_'), 'date' => $d, 'title' => $t !== '' ? $t : 'Pertemuan ' . $d, 'created_at' => date('Y-m-d H:i:s')];
                save_json('meetings.json', $meetings);
                flash('Pertemuan ' . $d . ' dibuat.', 'success');
            }
        }
        redirect('admin.php?tab=attendance');
    }
    if ($action === 'meeting_delete') {
        $meetings = load_json('meetings.json');
        $meetings = array_values(array_filter($meetings, function ($m) {
            return $m['id'] !== ($_POST['meeting_id'] ?? '');
        }));
        save_json('meetings.json', $meetings);
        flash('Pertemuan dihapus.', 'success');
        redirect('admin.php?tab=attendance');
    }
    if ($action === 'token_create') {
        $code = mb_strtoupper(trim($_POST['code'] ?? ''));
        $mid = trim($_POST['meeting_id'] ?? '');
        if ($code === '' || !preg_match('/^[A-Z0-9-]{4,16}$/', $code)) {
            flash('Kode token harus 4–16 karakter (huruf/angka/dash).', 'error');
        } elseif (find_meeting($mid) === null) {
            flash('Pertemuan tidak ditemukan.', 'error');
        } else {
            $tokens = load_tokens();
            $dup = false;
            foreach ($tokens as $t) {
                if (mb_strtoupper($t['code'] ?? '') === $code) {
                    $dup = true;
                }
            }
            if ($dup) {
                flash('Kode token "' . $code . '" sudah dipakai.', 'error');
            } else {
                $tokens[] = ['id' => uid('tok_'), 'code' => $code, 'meeting_id' => $mid, 'active' => true, 'created_at' => date('Y-m-d H:i:s')];
                save_json('tokens.json', $tokens);
                $mtg = find_meeting($mid);
                flash('Token ' . $code . ' dibuat untuk pertemuan ' . ($mtg['date'] ?? '') . '.', 'success');
            }
        }
        redirect('admin.php?tab=attendance');
    }
    if ($action === 'token_toggle') {
        $tokens = load_tokens();
        foreach ($tokens as &$t) {
            if ($t['id'] === ($_POST['token_id'] ?? '')) {
                $t['active'] = empty($t['active']);
                flash('Token ' . $t['code'] . ' ' . ($t['active'] ? 'diaktifkan.' : 'dinonaktifkan.'), 'success');
                break;
            }
        }
        unset($t);
        save_json('tokens.json', $tokens);
        redirect('admin.php?tab=attendance');
    }
    // Reset grafik kehadiran: hapus semua catatan check-in (grafik jadi 0).
    // Pertemuan, token, dan poin yang sudah diberikan TIDAK berubah.
    if ($action === 'att_chart_reset') {
        save_json('attendance.json', []);
        flash('Grafik kehadiran di-reset. Semua catatan check-in dihapus (grafik menjadi 0). Pertemuan, token, dan poin tidak berubah.', 'success');
        redirect('admin.php?tab=attendance');
    }

    // --- Events ---
    if ($action === 'event_create' || $action === 'event_update') {
        $events = load_json('events.json');
        [$poster, $posterErr] = upload_image('poster', 'posters');
        if ($posterErr !== null) {
            flash($posterErr, 'error');
            redirect('admin.php?tab=events');
        }
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'date_label' => trim($_POST['date_label'] ?? ''),
            'date_iso' => trim($_POST['date_iso'] ?? ''),
            'start_time' => trim($_POST['start_time'] ?? ''),
            'end_time' => trim($_POST['end_time'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'status' => in_array($_POST['status'] ?? '', ['draft', 'upcoming', 'active', 'finished'], true) ? $_POST['status'] : 'draft',
            'prize' => trim($_POST['prize'] ?? ''),
        ];
        if ($action === 'event_create') {
            $data['id'] = uid('ev_');
            $data['type'] = 'event';
            $data['poster'] = $poster ?: '';
            $data['joins'] = [];
            $data['created_at'] = date('Y-m-d H:i:s');
            $events[] = $data;
            notify('all', 'event', 'Event baru: ' . $data['title'] . ' — ' . $data['date_label'] . ' di ' . $data['location'] . '.', 'event.php?id=' . $data['id']);
            flash('Event dibuat.', 'success');
        } else {
            foreach ($events as &$ev) {
                if ($ev['id'] === ($_POST['event_id'] ?? '')) {
                    $data['id'] = $ev['id'];
                    $data['type'] = $ev['type'] ?? 'event';
                    $data['poster'] = $poster ?: $ev['poster'];
                    $data['joins'] = $ev['joins'] ?? [];
                    $data['created_at'] = $ev['created_at'] ?? date('Y-m-d H:i:s');
                    $ev = $data;
                    break;
                }
            }
            unset($ev);
            flash('Event diperbarui.', 'success');
        }
        save_json('events.json', $events);
        redirect('admin.php?tab=events');
    }
    if ($action === 'event_delete') {
        $events = load_json('events.json');
        $events = array_values(array_filter($events, function ($ev) {
            return $ev['id'] !== ($_POST['event_id'] ?? '') || ($ev['type'] ?? '') === 'game';
        }));
        save_json('events.json', $events);
        flash('Event dihapus.', 'success');
        redirect('admin.php?tab=events');
    }
    if ($action === 'game_event_status') {
        $events = load_json('events.json');
        foreach ($events as &$ev) {
            if (($ev['type'] ?? '') === 'game' && $ev['id'] === ($_POST['event_id'] ?? '')) {
                $ev['status'] = $_POST['status'] ?? $ev['status'];
                if ($ev['status'] === 'active') {
                    notify('all', 'event', 'Game ' . active_map_meta()['name'] . ' sudah dimulai! Buka menu Games untuk bermain.', 'games.php');
                }
                if ($ev['status'] === 'finished') {
                    // Event selesai: beri poin dari leaderboard Event Game.
                    $n = award_event_game_points($ev['id']);
                    // award_event_game_points() menyimpan events.json sendiri
                    // (menandai points_awarded); tandai juga di salinan lokal
                    // ini agar save_json() di bawah tidak menimpa flag itu.
                    $ev['points_awarded'] = true;
                    flash('Status game event diubah menjadi finished. Poin Event Game diberikan ke ' . $n . ' member dari leaderboard (Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1).', 'success');
                } else {
                    flash('Status game event diubah menjadi ' . $ev['status'] . '.', 'success');
                }
                break;
            }
        }
        unset($ev);
        save_json('events.json', $events);
        redirect('admin.php?tab=games');
    }
    // Beri poin Event Game secara manual dari leaderboard (bila belum diberikan).
    if ($action === 'game_event_award') {
        $n = award_event_game_points($_POST['event_id'] ?? '');
        flash($n > 0
            ? 'Poin Event Game diberikan ke ' . $n . ' member dari leaderboard (Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1).'
            : 'Tidak ada poin yang diberikan — leaderboard kosong atau poin event ini sudah diberikan.', $n > 0 ? 'success' : 'info');
        redirect('admin.php?tab=games');
    }
    // --- Custom games ---
    if ($action === 'game_create') {
        $games = load_json('games.json');
        $qs = [];
        $qtexts = $_POST['q'] ?? [];
        $atexts = $_POST['a'] ?? [];
        $otexts = $_POST['o'] ?? [];
        foreach ($qtexts as $i => $qt) {
            $qt = trim($qt);
            $at = trim($atexts[$i] ?? '');
            if ($qt === '' || $at === '') {
                continue;
            }
            $opts = array_values(array_filter(array_map('trim', explode(',', $otexts[$i] ?? ''))));
            $qs[] = ['q' => $qt, 'a' => $at, 'options' => $opts];
        }
        if (count($qs) < 2) {
            flash('Minimal 2 soal untuk membuat game.', 'error');
        } else {
            $games[] = [
                'id' => uid('gm_'),
                'title' => trim($_POST['title'] ?? 'Game Baru'),
                'description' => trim($_POST['description'] ?? ''),
                'template' => in_array($_POST['template'] ?? '', ['mcq', 'matching', 'fillblank', 'typing', 'flashcard', 'memory'], true) ? $_POST['template'] : 'mcq',
                'questions' => $qs,
                'created_by' => $user['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ];
            save_json('games.json', $games);
            flash('Game berhasil dibuat.', 'success');
        }
        redirect('admin.php?tab=games');
    }
    if ($action === 'game_delete') {
        $games = load_json('games.json');
        $games = array_values(array_filter($games, function ($g) {
            return $g['id'] !== ($_POST['game_id'] ?? '');
        }));
        save_json('games.json', $games);
        flash('Game dihapus.', 'success');
        redirect('admin.php?tab=games');
    }

    // --- Hall of Fame ---
    if ($action === 'hof_approve' || $action === 'hof_reject') {
        $arts = load_json('artworks.json');
        foreach ($arts as &$a) {
            if ($a['id'] === ($_POST['art_id'] ?? '')) {
                $a['status'] = $action === 'hof_approve' ? 'approved' : 'rejected';
                $a['comment'] = trim($_POST['comment'] ?? '');
                if (!empty($a['author_id'])) {
                    notify($a['author_id'], 'artwork', $action === 'hof_approve'
                        ? 'Karyamu "' . $a['title'] . '" diterima dan tampil di Hall of Fame!'
                        : 'Karyamu "' . $a['title'] . '" ditolak admin.' . ($a['comment'] !== '' ? ' Komentar admin: ' . $a['comment'] : ''), 'halloffame.php');
                }
                if ($action === 'hof_approve') {
                    check_badges(find_user_by_id($a['author_id'] ?? '') ?: ['id' => '', 'badges' => []]);
                }
                flash('Karya ' . ($action === 'hof_approve' ? 'disetujui.' : 'ditolak.'), 'success');
                break;
            }
        }
        unset($a);
        save_json('artworks.json', $arts);
        redirect('admin.php?tab=hof');
    }
    if ($action === 'hof_delete') {
        $arts = load_json('artworks.json');
        $arts = array_values(array_filter($arts, function ($a) {
            return $a['id'] !== ($_POST['art_id'] ?? '');
        }));
        save_json('artworks.json', $arts);
        flash('Karya dihapus.', 'success');
        redirect('admin.php?tab=hof');
    }

    // --- Announcements ---
    if ($action === 'ann_create') {
        $ann = load_json('announcements.json');
        $ann[] = [
            'id' => uid('ann_'),
            'title' => trim($_POST['title'] ?? ''),
            'body' => trim($_POST['body'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        save_json('announcements.json', $ann);
        notify('all', 'announcement', 'Pengumuman baru: ' . trim($_POST['title'] ?? ''), 'announcements.php');
        flash('Pengumuman dibuat.', 'success');
        redirect('admin.php?tab=announcements');
    }
    if ($action === 'ann_delete') {
        $ann = load_json('announcements.json');
        $ann = array_values(array_filter($ann, function ($a) {
            return $a['id'] !== ($_POST['ann_id'] ?? '');
        }));
        save_json('announcements.json', $ann);
        flash('Pengumuman dihapus.', 'success');
        redirect('admin.php?tab=announcements');
    }

    // --- Calendar ---
    if ($action === 'cal_add') {
        $cal = load_json('calendar.json');
        $cal[] = [
            'id' => uid('cal_'),
            'date' => trim($_POST['date'] ?? date('Y-m-d')),
            'title' => trim($_POST['title'] ?? ''),
            'type' => in_array($_POST['type'] ?? '', ['latihan', 'event', 'lomba', 'ujian'], true) ? $_POST['type'] : 'latihan',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        save_json('calendar.json', $cal);
        flash('Jadwal ditambahkan.', 'success');
        redirect('admin.php?tab=calendar');
    }
    if ($action === 'cal_delete') {
        $cal = load_json('calendar.json');
        $cal = array_values(array_filter($cal, function ($c) {
            return $c['id'] !== ($_POST['cal_id'] ?? '');
        }));
        save_json('calendar.json', $cal);
        flash('Jadwal dihapus.', 'success');
        redirect('admin.php?tab=calendar');
    }

    // --- Pengaturan map game ---
    if ($action === 'map_save') {
        $ms = load_map_settings();
        $nm = trim($_POST['name'] ?? '');
        $th = trim($_POST['theme'] ?? '');
        $am = trim($_POST['active_map'] ?? '');
        if ($nm !== '') {
            $ms['name'] = $nm;
        }
        if ($th !== '') {
            $ms['theme'] = $th;
        }
        // Ganti map aktif: nama/tema ikut reset ke default map baru.
        if ($am !== '' && $am !== ($ms['active_map'] ?? 'map-sakura') && isset(game_maps()[$am])) {
            $ms['active_map'] = $am;
            $ms['name'] = game_maps()[$am]['name'];
            $ms['theme'] = game_maps()[$am]['theme'];
        }
        save_json('map_settings.json', $ms);
        flash('Pengaturan map disimpan.', 'success');
        redirect('admin.php?tab=questions');
    }

    // --- URL server multiplayer (WebSocket) ---
    if ($action === 'multiplayer_save') {
        $wsUrl = trim($_POST['ws_url'] ?? '');
        // Kosong = multiplayer nonaktif. Bila diisi harus URL ws:// atau wss://.
        if ($wsUrl !== '' && !preg_match('#^wss?://[^\s/]+#', $wsUrl)) {
            flash('URL server tidak valid. Pakai format ws://... atau wss://... (contoh: wss://kaniku-multiplayer.onrender.com).', 'error');
            redirect('admin.php?tab=questions');
        }
        if (strlen($wsUrl) > 200) {
            $wsUrl = substr($wsUrl, 0, 200);
        }
        save_json('multiplayer.json', ['ws_url' => $wsUrl]);
        flash($wsUrl === '' ? 'Multiplayer dimatikan — game kembali single-player.' : 'URL server multiplayer disimpan. Pemain di HP berbeda kini bisa saling lihat.', 'success');
        redirect('admin.php?tab=questions');
    }

    // --- Soal gerbang Map Sakura (15 soal) ---
    if ($action === 'question_save') {
        $defaults = default_questions();
        $existing = load_questions();
        $oldImgs = [];
        foreach ($existing as $eq) {
            $oldImgs[(int) ($eq['n'] ?? 0)] = $eq['img'] ?? '';
        }
        $types = $_POST['qtype'] ?? [];
        $prompts = $_POST['qprompt'] ?? [];
        $displays = $_POST['qdisplay'] ?? [];
        $opts = $_POST['qopt'] ?? [];
        $answers = $_POST['qanswer'] ?? [];
        $qs = [];
        $imgErr = null;
        for ($n = 1; $n <= 15; $n++) {
            $type = ($types[$n] ?? 'mc') === 'essay' ? 'essay' : 'mc';
            $prompt = trim($prompts[$n] ?? '');
            $display = trim($displays[$n] ?? '');
            // Gambar soal (opsional) — field file qimg_{n}.
            $img = $oldImgs[$n] ?? '';
            if (!empty($_FILES['qimg_' . $n]) && $_FILES['qimg_' . $n]['error'] !== UPLOAD_ERR_NO_FILE) {
                [$up, $upErr] = upload_image('qimg_' . $n, 'questions');
                if ($up !== null) {
                    $img = $up;
                } elseif ($imgErr === null) {
                    $imgErr = $upErr;
                }
            }
            if ($prompt === '' && $display === '' && $img === '') {
                // Soal kosong: pakai contoh default gerbang ini.
                $qs[] = $defaults[$n - 1];
                continue;
            }
            $olist = array_values(array_filter(array_map('trim', $opts[$n] ?? []), function ($v) { return $v !== ''; }));
            $answer = trim($answers[$n] ?? '');
            if ($type === 'mc') {
                if (count($olist) < 2) {
                    $olist = array_pad($olist, 2, '');
                }
                if ($answer === '' && !empty($olist[0])) {
                    $answer = $olist[0];
                }
            } else {
                $olist = [];
            }
            $qs[] = [
                'n' => $n,
                'type' => $type,
                'prompt' => $prompt !== '' ? $prompt : 'Jawab pertanyaan ini',
                'display' => $display,
                'img' => $img,
                'options' => array_slice($olist, 0, 4),
                'answer' => $answer,
            ];
        }
        save_json('questions.json', $qs);
        flash($imgErr !== null ? '15 soal disimpan. Catatan gambar: ' . $imgErr : '15 soal gerbang disimpan.', $imgErr !== null ? 'warn' : 'success');
        redirect('admin.php?tab=questions');
    }
}

// ---------- Data for views ----------
$users = load_json('users.json');
$members = array_values(array_filter($users, function ($u) { return ($u['role'] ?? '') === 'member'; }));
$pendingUsers = array_values(array_filter($members, function ($u) { return ($u['status'] ?? '') === 'pending'; }));
$approvedMembers = array_values(array_filter($members, function ($u) { return ($u['status'] ?? '') === 'approved'; }));
$att = load_json('attendance.json');
$arts = load_json('artworks.json');
$pendingArts = array_values(array_filter($arts, function ($a) { return ($a['status'] ?? '') === 'pending'; }));
$events = load_json('events.json');
$games = load_json('games.json');
$ann = load_json('announcements.json');
$cal = load_json('calendar.json');
$meetings = load_meetings();
$tokens = load_tokens();
$questions = load_questions();
$mapSettings = load_map_settings();
$mpSettings = load_multiplayer_settings();
$podiumTop = podium_top3();
$adminNotifs = user_notifications($user['id'], 10);

$activeMembers = 0;
$monthAgo = date('Y-m-d', strtotime('-30 days'));
foreach ($approvedMembers as $m) {
    foreach ($att as $a) {
        if (($a['user_id'] ?? '') === $m['id'] && ($a['date'] ?? '') >= $monthAgo) {
            $activeMembers++;
            break;
        }
    }
}
$pendingTotal = count($pendingUsers) + count($pendingArts);

$attDate = $_GET['date'] ?? date('Y-m-d');
$attByDate = array_values(array_filter($att, function ($a) use ($attDate) { return ($a['date'] ?? '') === $attDate; }));

$editMember = null;
if (!empty($_GET['edit_member'])) {
    $editMember = find_user_by_id($_GET['edit_member']);
}
$editEvent = null;
if (!empty($_GET['edit_event'])) {
    foreach ($events as $ev) {
        if ($ev['id'] === $_GET['edit_event']) {
            $editEvent = $ev;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="dash-head reveal visible">
    <span class="avatar-lg initial"><?= icon('crown', 34) ?></span>
    <div>
      <h1>Dashboard Admin</h1>
      <p>Halo, <?= e($user['name']) ?> &bull; Panel Admin KANIKU</p>
    </div>
    <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-soft btn-sm" href="changepassword.php"><?= icon('lock', 16) ?> Ganti Password</a>
      <a class="btn btn-ghost btn-sm" href="logout.php"><?= icon('logout', 16) ?> Keluar</a>
    </div>
  </div>

  <div class="tabs reveal visible">
    <a href="admin.php?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">Overview</a>
    <a href="admin.php?tab=members" class="<?= $tab === 'members' ? 'active' : '' ?>">Member <?= count($pendingUsers) ? '(' . count($pendingUsers) . ')' : '' ?></a>
    <a href="admin.php?tab=attendance" class="<?= $tab === 'attendance' ? 'active' : '' ?>">Absensi</a>
    <a href="admin.php?tab=events" class="<?= $tab === 'events' ? 'active' : '' ?>">Event</a>
    <a href="admin.php?tab=games" class="<?= $tab === 'games' ? 'active' : '' ?>">Game</a>
    <a href="admin.php?tab=questions" class="<?= $tab === 'questions' ? 'active' : '' ?>">Soal &amp; Map</a>
    <a href="admin.php?tab=hof" class="<?= $tab === 'hof' ? 'active' : '' ?>">Hall of Fame <?= count($pendingArts) ? '(' . count($pendingArts) . ')' : '' ?></a>
    <a href="admin.php?tab=announcements" class="<?= $tab === 'announcements' ? 'active' : '' ?>">Pengumuman</a>
    <a href="admin.php?tab=calendar" class="<?= $tab === 'calendar' ? 'active' : '' ?>">Kalender</a>
    <a href="admin.php?tab=stats" class="<?= $tab === 'stats' ? 'active' : '' ?>">Statistik</a>
  </div>

  <?php if ($tab === 'overview'): ?>
    <div class="stat-cards reveal visible">
      <div class="stat-card"><span class="sc-ico"><?= icon('users', 24) ?></span><div><b><?= count($approvedMembers) ?></b><span>Total Member</span></div></div>
      <div class="stat-card"><span class="sc-ico"><?= icon('zap', 24) ?></span><div><b><?= $activeMembers ?></b><span>Member Aktif (30 hari)</span></div></div>
      <div class="stat-card"><span class="sc-ico"><?= icon('torii', 24) ?></span><div><b><?= count($events) ?></b><span>Total Event</span></div></div>
      <div class="stat-card"><span class="sc-ico"><?= icon('calendar', 24) ?></span><div><b><?= count($att) ?></b><span>Total Absensi</span></div></div>
      <div class="stat-card"><span class="sc-ico"><?= icon('image', 24) ?></span><div><b><?= count($arts) ?></b><span>Total Karya</span></div></div>
      <div class="stat-card"><span class="sc-ico"><?= icon('bell', 24) ?></span><div><b><?= $pendingTotal ?></b><span>Pending Approval</span></div></div>
    </div>
    <div class="dash-grid two">
      <div class="panel reveal visible">
        <h2><?= icon('users', 20) ?> Pendaftaran Menunggu</h2>
        <p class="panel-sub">Akun member baru yang perlu disetujui.</p>
        <?php if (empty($pendingUsers)): ?>
          <p class="empty">Tidak ada pendaftaran menunggu.</p>
        <?php else: ?>
          <?php foreach ($pendingUsers as $m): ?>
            <div class="lb-row">
              <span class="lb-rank"><?= icon('user', 16) ?></span>
              <div><div class="lb-name"><?= e($m['name']) ?></div><div class="lb-sub">Kelas <?= e(kelas_jurusan($m)) ?> &bull; @<?= e($m['username'] ?? '-') ?></div></div>
              <span class="lb-pts"><a class="btn-mini plum" href="admin.php?tab=members">Kelola</a></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="panel reveal visible">
        <h2><?= icon('image', 20) ?> Karya Menunggu Review</h2>
        <p class="panel-sub">Karya yang perlu disetujui sebelum tampil di Hall of Fame.</p>
        <?php if (empty($pendingArts)): ?>
          <p class="empty">Tidak ada karya menunggu.</p>
        <?php else: ?>
          <?php foreach ($pendingArts as $a): ?>
            <div class="lb-row">
              <span class="lb-rank"><?= icon('image', 16) ?></span>
              <div><div class="lb-name"><?= e($a['title']) ?></div><div class="lb-sub"><?= e($a['author']) ?> &bull; <?= e($a['kelas']) ?> &bull; <?= e($a['category']) ?></div></div>
              <span class="lb-pts"><a class="btn-mini plum" href="admin.php?tab=hof">Review</a></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel reveal visible" id="notif-admin">
      <h2><?= icon('bell', 20) ?> Notifikasi Admin</h2>
      <p class="panel-sub">Pendaftaran baru, karya menunggu review, dan info lainnya.</p>
      <?php if (empty($adminNotifs)): ?>
        <p class="empty">Belum ada notifikasi.</p>
      <?php else: ?>
        <?php foreach ($adminNotifs as $n): $isRead = in_array($user['id'], $n['read_by'] ?? [], true); ?>
          <div class="notif <?= $isRead ? '' : 'unread' ?>">
            <span class="n-ico"><?= icon('mega', 18) ?></span>
            <div style="flex:1">
              <p><?= e($n['message']) ?></p>
              <time><?= e($n['created_at']) ?></time>
              <?php if (!empty($n['link'])): ?> &nbsp;<a href="<?= e($n['link']) ?>" style="font-weight:800;color:var(--plum)">Lihat &rarr;</a><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <form method="post" action="admin.php?tab=overview" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="mark_all_read">
          <button class="btn btn-ghost btn-sm" type="submit">Tandai semua dibaca</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'members'): ?>
    <?php if ($editMember): ?>
      <div class="panel reveal visible">
        <h2><?= icon('edit', 20) ?> Edit Member: <?= e($editMember['name']) ?></h2>
        <form method="post" action="admin.php?tab=members">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="member_edit">
          <input type="hidden" name="user_id" value="<?= e($editMember['id']) ?>">
          <div class="form-row">
            <div class="field"><label>Nama Lengkap</label><input type="text" name="name" value="<?= e($editMember['name']) ?>" required></div>
            <div class="field"><label>Kelas</label>
              <select name="kelas" required>
                <?php $curK = $editMember['kelas'] ?? ''; if ($curK !== '' && !in_array($curK, kelas_options(), true)): ?><option value="<?= e($curK) ?>" selected><?= e($curK) ?></option><?php endif; ?>
                <?php foreach (kelas_options() as $k): ?><option value="<?= e($k) ?>" <?= $curK === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="field"><label>Jurusan</label>
              <select name="jurusan">
                <?php $curJ = $editMember['jurusan'] ?? ''; if ($curJ !== '' && !in_array($curJ, jurusan_options(), true)): ?><option value="<?= e($curJ) ?>" selected><?= e($curJ) ?></option><?php endif; ?>
                <?php foreach (jurusan_options() as $j): ?><option value="<?= e($j) ?>" <?= $curJ === $j ? 'selected' : '' ?>><?= e($j) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Username</label><input type="text" name="username" value="<?= e($editMember['username'] ?? '') ?>"></div>
          </div>
          <div class="form-row">
            <div class="field"><label>Role</label>
              <select name="role">
                <option value="member" <?= ($editMember['role'] ?? '') === 'member' ? 'selected' : '' ?>>Member</option>
                <option value="admin" <?= ($editMember['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>
            <div class="field"><label>Status</label>
              <select name="status">
                <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'inactive' => 'Nonaktif'] as $v => $l): ?>
                  <option value="<?= $v ?>" <?= ($editMember['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button class="btn btn-plum" type="submit">Simpan Perubahan</button>
          <a class="btn btn-ghost" href="admin.php?tab=members">Batal</a>
        </form>
      </div>
    <?php endif; ?>
    <div class="panel reveal visible">
      <h2><?= icon('users', 20) ?> Kelola Member</h2>
      <p class="panel-sub">Setujui/tolak pendaftaran, edit data, reset password, nonaktifkan, atau hapus member.</p>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Nama</th><th>Kelas / Jurusan</th><th>Username</th><th>Poin</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($users as $m): ?>
              <tr>
                <td><b><?= e($m['name']) ?></b><?= ($m['role'] ?? '') === 'admin' ? ' <span class="chip">Admin</span>' : '' ?></td>
                <td><?= e(kelas_jurusan($m)) ?></td>
                <td>@<?= e($m['username'] ?? '-') ?></td>
                <td><?= (int) ($m['points'] ?? 0) ?></td>
                <td>
                  <?php $st = $m['status'] ?? ''; ?>
                  <span class="badge <?= $st === 'approved' ? 'badge-approved' : ($st === 'pending' ? 'badge-pending' : ($st === 'rejected' ? 'badge-rejected' : 'badge-draft')) ?>"><?= e($st) ?></span>
                </td>
                <td>
                  <div class="row-actions">
                    <?php if ($st === 'pending'): ?>
                      <form method="post" action="admin.php?tab=members"><?= csrf_field() ?><input type="hidden" name="action" value="member_approve"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini ok" type="submit">Setujui</button></form>
                      <form method="post" action="admin.php?tab=members"><?= csrf_field() ?><input type="hidden" name="action" value="member_reject"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini danger" type="submit">Tolak</button></form>
                    <?php endif; ?>
                    <a class="btn-mini soft" href="admin.php?tab=members&amp;edit_member=<?= e($m['id']) ?>">Edit</a>
                    <?php if (($m['role'] ?? '') === 'admin'): ?>
                      <?php if ($m['id'] !== $user['id']): ?>
                        <form method="post" action="admin.php?tab=members" style="display:inline" onsubmit="return confirm('Jadikan <?= e($m['name']) ?> sebagai Member? (Dia akan kembali masuk tabel absensi.)')"><?= csrf_field() ?><input type="hidden" name="action" value="member_demote"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini warn" type="submit">Jadikan Member</button></form>
                      <?php endif; ?>
                    <?php else: ?>
                      <form method="post" action="admin.php?tab=members" style="display:inline" onsubmit="return confirm('Jadikan <?= e($m['name']) ?> sebagai Admin? (Dia akan keluar dari tabel absensi.)')"><?= csrf_field() ?><input type="hidden" name="action" value="member_promote"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini plum" type="submit">Jadikan Admin</button></form>
                    <?php endif; ?>
                    <?php if (($m['role'] ?? '') !== 'admin'): ?>
                      <form method="post" action="admin.php?tab=members" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="member_toggle"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini warn" type="submit"><?= $st === 'inactive' ? 'Aktifkan' : 'Nonaktifkan' ?></button></form>
                      <form method="post" action="admin.php?tab=members" style="display:inline" onsubmit="return confirm('Hapus member <?= e($m['name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="member_delete"><input type="hidden" name="user_id" value="<?= e($m['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
                    <?php endif; ?>
                  </div>
                  <?php if (($m['role'] ?? '') !== 'admin'): ?>
                    <form method="post" action="admin.php?tab=members" style="margin-top:6px;display:flex;gap:6px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="member_reset">
                      <input type="hidden" name="user_id" value="<?= e($m['id']) ?>">
                      <input type="text" name="new_password" placeholder="Password baru (min 8)" style="border:1.5px solid var(--line);border-radius:10px;padding:6px 10px;font-size:.85rem;width:170px" required minlength="8">
                      <button class="btn-mini soft" type="submit">Reset Password</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'attendance'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('calendar', 20) ?> Kelola Absensi</h2>
      <p class="panel-sub">Murid absen dengan token yang dibuat admin. Satu token untuk satu pertemuan. Tabel absensi hanya berisi member/murid biasa — <b>admin tidak disertakan</b> (member yang dipromosikan jadi admin otomatis keluar dari tabel).</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
        <form method="post" action="admin.php?tab=attendance"><?= csrf_field() ?><input type="hidden" name="action" value="att_toggle"><button class="btn <?= load_settings()['attendance_open'] ? 'btn-ghost' : 'btn-plum' ?>" type="submit"><?= load_settings()['attendance_open'] ? 'Tutup Absensi' : 'Buka Absensi' ?></button></form>
        <span style="font-weight:800;color:var(--plum);align-self:center">Status absensi: <b><?= load_settings()['attendance_open'] ? 'Dibuka' : 'Ditutup' ?></b></span>
      </div>
      <div class="form-row">
        <div class="field"><label>Buat Pertemuan Baru</label>
          <form method="post" action="admin.php?tab=attendance" style="display:flex;gap:8px;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="meeting_create">
            <input type="date" name="date" value="<?= date('Y-m-d') ?>" required style="border:1.5px solid var(--line);border-radius:12px;padding:9px 12px">
            <input type="text" name="title" placeholder="Judul pertemuan (opsional)" style="flex:1;min-width:160px;border:1.5px solid var(--line);border-radius:12px;padding:9px 12px">
            <button class="btn btn-plum btn-sm" type="submit">Buat Pertemuan</button>
          </form>
        </div>
        <div class="field"><label>Buat Token Absensi</label>
          <form method="post" action="admin.php?tab=attendance" style="display:flex;gap:8px;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="token_create">
            <select name="meeting_id" required style="border:1.5px solid var(--line);border-radius:12px;padding:9px 12px">
              <option value="" disabled selected>Pilih Pertemuan</option>
              <?php foreach ($meetings as $mt): ?><option value="<?= e($mt['id']) ?>"><?= e($mt['date']) ?> — <?= e($mt['title']) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="code" placeholder="Kode token (cth: SAKURA-7K2P)" required style="flex:1;min-width:140px;border:1.5px solid var(--line);border-radius:12px;padding:9px 12px;text-transform:uppercase">
            <button class="btn btn-plum btn-sm" type="submit">Buat Token</button>
          </form>
        </div>
      </div>
      <?php if (!empty($meetings)): ?>
        <div class="table-wrap" style="margin-top:8px">
          <table class="tbl">
            <thead><tr><th>Tanggal</th><th>Pertemuan</th><th>Token</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($meetings as $mt): $mtTokens = array_values(array_filter($tokens, function ($t) use ($mt) { return ($t['meeting_id'] ?? '') === $mt['id']; })); ?>
                <tr>
                  <td><b><?= e($mt['date']) ?></b></td>
                  <td><?= e($mt['title']) ?></td>
                  <td>
                    <?php if (empty($mtTokens)): ?><span style="color:var(--muted);font-weight:600">Belum ada token</span><?php endif; ?>
                    <?php foreach ($mtTokens as $tk): ?>
                      <form method="post" action="admin.php?tab=attendance" style="display:inline-block;margin:2px"><?= csrf_field() ?><input type="hidden" name="action" value="token_toggle"><input type="hidden" name="token_id" value="<?= e($tk['id']) ?>"><button class="btn-mini <?= !empty($tk['active']) ? 'ok' : 'warn' ?>" type="submit" title="Klik untuk <?= !empty($tk['active']) ? 'nonaktifkan' : 'aktifkan' ?>"><?= e($tk['code']) ?> <?= !empty($tk['active']) ? '✓' : '(nonaktif)' ?></button></form>
                    <?php endforeach; ?>
                  </td>
                  <td>
                    <form method="post" action="admin.php?tab=attendance" onsubmit="return confirm('Hapus pertemuan <?= e($mt['date']) ?>? Token & catatan absensinya tetap tersimpan di riwayat.')"><?= csrf_field() ?><input type="hidden" name="action" value="meeting_delete"><input type="hidden" name="meeting_id" value="<?= e($mt['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="empty">Belum ada pertemuan. Buat pertemuan dulu, lalu buat token untuk pertemuan itu.</p>
      <?php endif; ?>
    </div>

    <div class="panel reveal visible">
      <h2><?= icon('users', 20) ?> Tabel Absensi</h2>
      <p class="panel-sub">Kolom: No., Nama, Kelas, Jurusan, lalu satu kolom per tanggal pertemuan (format d/m). Diurutkan A-Z di dalam Kelas 10, lalu A-Z Kelas 11, lalu A-Z Kelas 12. Sel: ✓ = hadir, "-" = tidak absen, kosong = pertemuan sebelum member mendaftar. Tabel update otomatis saat member baru register.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <a class="btn btn-soft" href="export.php?type=matrix"><?= icon('download', 16) ?> Export Excel (Tabel Lengkap)</a>
      </div>
      <?php
      $attRows = array_values(array_filter($users, function ($u) { return ($u['role'] ?? '') === 'member' && ($u['status'] ?? '') === 'approved'; }));
      usort($attRows, function ($a, $b) {
          $d = ((int) ($a['kelas'] ?? 0)) <=> ((int) ($b['kelas'] ?? 0));
          return $d !== 0 ? $d : strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? ''));
      });
      $present = [];
      foreach ($att as $a) {
          $present[($a['user_id'] ?? '') . '|' . ($a['date'] ?? '')] = true;
          if (!empty($a['meeting_id'])) {
              $present[($a['user_id'] ?? '') . '|m:' . $a['meeting_id']] = true;
          }
      }
      ?>
      <?php if (empty($attRows)): ?>
        <p class="empty">Belum ada member yang disetujui.</p>
      <?php elseif (empty($meetings)): ?>
        <p class="empty">Belum ada pertemuan. Buat pertemuan di panel atas dulu.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>No.</th><th>Nama</th><th>Kelas</th><th>Jurusan</th><?php foreach ($meetings as $mt): ?><th><?= e(date('j/n', strtotime($mt['date']))) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <?php $attNo = 0; foreach ($attRows as $u): $attNo++; $joined = substr($u['created_at'] ?? '0000-00-00', 0, 10); ?>
                <tr>
                  <td><?= $attNo ?></td>
                  <td><b><?= e($u['name']) ?></b></td>
                  <td><?= e($u['kelas']) ?></td>
                  <td><?= e($u['jurusan'] ?? '') ?></td>
                  <?php foreach ($meetings as $mt): $md = $mt['date'] ?? ''; ?>
                    <td style="text-align:center">
                      <?php if (!empty($present[$u['id'] . '|m:' . $mt['id']]) || !empty($present[$u['id'] . '|' . $md])): ?>✓<?php elseif ($md < $joined): ?><?php else: ?>-<?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel reveal visible">
      <h2><?= icon('chart', 20) ?> Grafik Kehadiran per Pertemuan</h2>
      <p class="panel-sub">Jumlah murid hadir di setiap pertemuan (dari data absensi). Label tanggal format d/m. Grafik ini hanya tampil di panel admin.</p>
      <?php
      $chartData = [];
      foreach ($meetings as $mt) {
          $c = 0;
          foreach ($att as $a) {
              if ((($a['meeting_id'] ?? '') === $mt['id']) || (($a['date'] ?? '') === ($mt['date'] ?? ''))) {
                  $c++;
              }
          }
          $chartData[] = ['label' => date('j/n', strtotime($mt['date'] ?? '')), 'title' => $mt['title'] ?? '', 'date' => $mt['date'] ?? '', 'count' => $c];
      }
      $maxC = 1;
      foreach ($chartData as $d) {
          $maxC = max($maxC, $d['count']);
      }
      ?>
      <?php if (empty($chartData)): ?>
        <p class="empty">Belum ada pertemuan. Buat pertemuan di panel atas dulu.</p>
      <?php else: foreach ($chartData as $d): ?>
        <div class="bar-row"><span class="bar-label" title="<?= e($d['date']) ?> — <?= e($d['title']) ?>"><?= e($d['label']) ?></span><div class="bar-track"><i style="width:<?= round($d['count'] / $maxC * 100) ?>%"></i></div><span class="bar-val"><?= $d['count'] ?> orang</span></div>
      <?php endforeach; endif; ?>
      <form method="post" action="admin.php?tab=attendance" style="margin-top:14px" onsubmit="return confirm('Reset grafik kehadiran? Semua catatan check-in akan dihapus dan grafik menjadi 0. Pertemuan dan token tidak dihapus, poin yang sudah diberikan tidak berubah.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="att_chart_reset">
        <button class="btn btn-ghost" type="submit"><?= icon('refresh', 16) ?> Reset Grafik Kehadiran</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'events'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('torii', 20) ?> <?= $editEvent ? 'Edit Event: ' . e($editEvent['title']) : 'Buat Event Baru' ?></h2>
      <p class="panel-sub">Status: Draft / Upcoming / Active / Finished. Event Active tampil sebagai "Berlangsung".</p>
      <form method="post" action="admin.php?tab=events" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editEvent ? 'event_update' : 'event_create' ?>">
        <?php if ($editEvent): ?><input type="hidden" name="event_id" value="<?= e($editEvent['id']) ?>"><?php endif; ?>
        <div class="form-row">
          <div class="field"><label>Nama Event</label><input type="text" name="title" value="<?= e($editEvent['title'] ?? '') ?>" required placeholder="cth: Hiragana Contest"></div>
          <div class="field"><label>Lokasi</label><input type="text" name="location" value="<?= e($editEvent['location'] ?? '') ?>" required placeholder="cth: Main Hall"></div>
        </div>
        <div class="field"><label>Deskripsi</label><textarea name="description" rows="2" placeholder="Deskripsi singkat event..."><?= e($editEvent['description'] ?? '') ?></textarea></div>
        <div class="form-row">
          <div class="field"><label>Tanggal (tampilan)</label><input type="text" name="date_label" value="<?= e($editEvent['date_label'] ?? '') ?>" required placeholder="cth: Sat, 22 Nov 2026"></div>
          <div class="field"><label>Tanggal (ISO, untuk urutan)</label><input type="date" name="date_iso" value="<?= e($editEvent['date_iso'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Waktu Mulai</label><input type="time" name="start_time" value="<?= e($editEvent['start_time'] ?? '') ?>"></div>
          <div class="field"><label>Waktu Selesai</label><input type="time" name="end_time" value="<?= e($editEvent['end_time'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Hadiah</label><input type="text" name="prize" value="<?= e($editEvent['prize'] ?? '') ?>" placeholder="cth: Sertifikat + Merch KANIKU"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Status</label>
            <select name="status">
              <?php foreach (['draft' => 'Draft', 'upcoming' => 'Upcoming', 'active' => 'Active (Berlangsung)', 'finished' => 'Finished (Selesai)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($editEvent['status'] ?? 'upcoming') === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Poster <span style="color:var(--muted);font-weight:600">(opsional, maks 2 MB)</span></label><input type="file" name="poster" accept="image/jpeg,image/png,image/webp,image/gif"></div>
        </div>
        <button class="btn btn-plum" type="submit"><?= $editEvent ? 'Simpan Perubahan' : 'Buat Event' ?></button>
        <?php if ($editEvent): ?><a class="btn btn-ghost" href="admin.php?tab=events">Batal</a><?php endif; ?>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('torii', 20) ?> Daftar Event</h2>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Event</th><th>Tanggal</th><th>Lokasi</th><th>Status</th><th>Peserta</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($events as $ev): if (($ev['type'] ?? '') === 'game') continue; ?>
              <tr>
                <td><b><?= e($ev['title']) ?></b><br><span style="color:var(--muted);font-size:.85rem"><?= e($ev['prize'] ?? '') ?></span></td>
                <td><?= e($ev['date_label']) ?><?= !empty($ev['start_time']) ? '<br>' . e($ev['start_time']) . '–' . e($ev['end_time']) : '' ?></td>
                <td><?= e($ev['location']) ?></td>
                <td><span class="badge <?= ($ev['status'] ?? '') === 'active' ? 'badge-active' : (($ev['status'] ?? '') === 'upcoming' ? 'badge-upcoming' : (($ev['status'] ?? '') === 'finished' ? 'badge-finished' : 'badge-draft')) ?>"><?= e($ev['status']) ?></span></td>
                <td><?= count($ev['joins'] ?? []) ?></td>
                <td>
                  <div class="row-actions">
                    <a class="btn-mini soft" href="admin.php?tab=events&amp;edit_event=<?= e($ev['id']) ?>">Edit</a>
                    <a class="btn-mini soft" href="event.php?id=<?= e($ev['id']) ?>" target="_blank">Lihat</a>
                    <form method="post" action="admin.php?tab=events" style="display:inline" onsubmit="return confirm('Hapus event ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="event_delete"><input type="hidden" name="event_id" value="<?= e($ev['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'games'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('gamepad', 20) ?> Game Event: <?= e(active_map_meta()['name']) ?></h2>
      <p class="panel-sub">Game utama ekskul (di-embed di website). Admin memulai/menutup akses game dari sini — panel admin di dalam game juga bisa memulai.</p>
      <?php $gameEv = null; foreach ($events as $ev) { if (($ev['type'] ?? '') === 'game') { $gameEv = $ev; } } ?>
      <?php if ($gameEv): ?>
        <p style="font-weight:800;margin-bottom:12px">Status saat ini: <span class="badge <?= ($gameEv['status'] ?? '') === 'active' ? 'badge-active' : 'badge-upcoming' ?>"><?= e($gameEv['status']) ?></span><?php if (!empty($gameEv['points_awarded'])): ?> &bull; <span class="badge badge-approved">Poin event sudah diberikan</span><?php endif; ?></p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <form method="post" action="admin.php?tab=games"><?= csrf_field() ?><input type="hidden" name="action" value="game_event_status"><input type="hidden" name="event_id" value="<?= e($gameEv['id']) ?>"><input type="hidden" name="status" value="active"><button class="btn btn-plum" type="submit"><?= icon('play', 16) ?> Mulai Game</button></form>
          <form method="post" action="admin.php?tab=games"><?= csrf_field() ?><input type="hidden" name="action" value="game_event_status"><input type="hidden" name="event_id" value="<?= e($gameEv['id']) ?>"><input type="hidden" name="status" value="finished"><button class="btn btn-ghost" type="submit">Akhiri Game</button></form>
          <form method="post" action="admin.php?tab=games" onsubmit="return confirm('Beri poin Event Game dari leaderboard <?= e(active_map_meta()['name']) ?>? Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1.')"><?= csrf_field() ?><input type="hidden" name="action" value="game_event_award"><input type="hidden" name="event_id" value="<?= e($gameEv['id']) ?>"><button class="btn btn-soft" type="submit"><?= icon('trophy', 16) ?> Beri Poin Event</button></form>
          <a class="btn btn-soft" href="games/<?= e(active_map_key()) ?>/" target="_blank">Buka Game (Admin)</a>
        </div>
        <p class="panel-sub" style="margin-top:14px">Mengakhiri game otomatis memberi poin dari leaderboard <?= e(active_map_meta()['name']) ?> (Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1) dan me-reset leaderboard game untuk event berikutnya. Soal dan pengaturan map diedit di tab <a href="admin.php?tab=questions" style="color:var(--plum);text-decoration:underline">Soal &amp; Map</a>.</p>
      <?php endif; ?>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('plus', 20) ?> Buat Game Tanpa Coding</h2>
      <p class="panel-sub">Pilih template, isi soal manual, member bisa langsung memainkannya. Format soal: Soal | Jawaban | Pilihan (khusus Multiple Choice, pisahkan dengan koma).</p>
      <form method="post" action="admin.php?tab=games" id="gameBuilder">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="game_create">
        <div class="form-row">
          <div class="field"><label>Judul Game</label><input type="text" name="title" required placeholder="cth: Kuis Hiragana Dasar"></div>
          <div class="field"><label>Template</label>
            <select name="template" id="gameTemplate">
              <option value="mcq">Multiple Choice</option>
              <option value="matching">Matching</option>
              <option value="fillblank">Fill Blank</option>
              <option value="typing">Typing</option>
              <option value="flashcard">Flashcard</option>
              <option value="memory">Memory Game</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Deskripsi</label><input type="text" name="description" placeholder="Deskripsi singkat game..."></div>
        <div id="qRows">
          <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="form-row q-row">
              <div class="field"><label>Soal <?= $i + 1 ?></label><input type="text" name="q[]" placeholder="cth: あ"></div>
              <div class="field"><label>Jawaban</label><input type="text" name="a[]" placeholder="cth: a"></div>
              <div class="field"><label>Pilihan (MCQ, koma)</label><input type="text" name="o[]" placeholder="cth: i, u, e"></div>
            </div>
          <?php endfor; ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-soft" type="button" id="addQRow"><?= icon('plus', 16) ?> Tambah Soal</button>
          <button class="btn btn-plum" type="submit">Simpan Game</button>
        </div>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('gamepad', 20) ?> Game Buatan Admin</h2>
      <?php if (empty($games)): ?>
        <p class="empty">Belum ada game buatan admin. Mini Games bawaan (Hiragana, Katakana, dll.) selalu tersedia di halaman Games.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Judul</th><th>Template</th><th>Soal</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($games as $g): ?>
                <tr>
                  <td><b><?= e($g['title']) ?></b><br><span style="color:var(--muted);font-size:.85rem"><?= e($g['description'] ?? '') ?></span></td>
                  <td><span class="chip"><?= e($g['template']) ?></span></td>
                  <td><?= count($g['questions'] ?? []) ?></td>
                  <td>
                    <div class="row-actions">
                      <a class="btn-mini soft" href="play.php?id=<?= e($g['id']) ?>" target="_blank">Mainkan</a>
                      <form method="post" action="admin.php?tab=games" style="display:inline" onsubmit="return confirm('Hapus game ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="game_delete"><input type="hidden" name="game_id" value="<?= e($g['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'questions'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('torii', 20) ?> Pengaturan Map</h2>
      <p class="panel-sub">Pilih <b>Map Aktif</b> untuk mengganti map yang dimainkan (Map Sakura, Desa Salju, atau Map Pantai) — nama/tema ikut menyesuaikan. Soal gerbang sama untuk ketiga map. Nama dan tema map tampil di halaman Games dan di dalam game.</p>
      <form method="post" action="admin.php?tab=questions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="map_save">
        <div class="form-row">
          <div class="field"><label>Map Aktif</label><select name="active_map"><?php foreach (game_maps() as $mk => $mm): ?><option value="<?= e($mk) ?>"<?= ($mapSettings['active_map'] ?? 'map-sakura') === $mk ? ' selected' : '' ?>><?= e($mm['name']) ?> — <?= e($mm['theme']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Nama Map</label><input type="text" name="name" value="<?= e($mapSettings['name']) ?>" required></div>
          <div class="field"><label>Tema Map</label><input type="text" name="theme" value="<?= e($mapSettings['theme']) ?>" required placeholder="cth: Taman Sakura"></div>
        </div>
        <button class="btn btn-plum" type="submit">Simpan Pengaturan Map</button>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('users', 20) ?> Multiplayer — Main Bareng Antar-HP</h2>
      <p class="panel-sub">Supaya murid di HP berbeda bisa saling lihat di map yang sama, isi <b>URL server WebSocket</b> di bawah (dari Render, diawali <b>wss://</b>). Kosongkan untuk mematikan multiplayer — game kembali single-player (tetap live antar-tab di perangkat yang sama). Server Render gratis tidur setelah ~15 menit tidak dipakai; koneksi pertama butuh ~30–60 detik untuk membangunkan server.</p>
      <form method="post" action="admin.php?tab=questions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="multiplayer_save">
        <div class="field"><label>URL Server WebSocket</label><input type="text" name="ws_url" value="<?= e($mpSettings['ws_url'] ?? '') ?>" placeholder="cth: wss://kaniku-multiplayer.onrender.com"></div>
        <button class="btn btn-plum" type="submit">Simpan URL Multiplayer</button>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('edit', 20) ?> Soal Gerbang (15 Soal)</h2>
      <p class="panel-sub">Setiap gerbang torii menampilkan satu soal. <b>Pilihan Ganda:</b> tulis 4 opsi dan jawaban benar. <b>Esai:</b> murid mengetik jawabannya sendiri di bar input di dalam game (jawaban dibandingkan tanpa memperhatikan huruf besar/kecil dan spasi berlebih). Gambar soal opsional (maks 2 MB). Soal yang dibiarkan kosong kembali ke contoh hiragana default.</p>
      <form method="post" action="admin.php?tab=questions" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="question_save">
        <?php foreach ($questions as $q): $n = (int) ($q['n'] ?? 0); $isEssay = ($q['type'] ?? 'mc') === 'essay'; ?>
          <div class="panel" style="box-shadow:none;border:1.5px solid var(--line);padding:16px;margin-bottom:14px">
            <h3 style="font-size:1rem;margin-bottom:10px">Gerbang <?= $n ?></h3>
            <div class="form-row">
              <div class="field"><label>Tipe Soal</label>
                <select name="qtype[<?= $n ?>]">
                  <option value="mc" <?= !$isEssay ? 'selected' : '' ?>>Pilihan Ganda (4 opsi)</option>
                  <option value="essay" <?= $isEssay ? 'selected' : '' ?>>Esai (ketik jawaban)</option>
                </select>
              </div>
              <div class="field"><label>Tampilan Besar (opsional)</label><input type="text" name="qdisplay[<?= $n ?>]" value="<?= e($q['display'] ?? '') ?>" placeholder="cth: あ / さくら"></div>
            </div>
            <div class="field"><label>Pertanyaan</label><input type="text" name="qprompt[<?= $n ?>]" value="<?= e($q['prompt'] ?? '') ?>" placeholder="cth: Huruf hiragana ini dibaca…"></div>
            <div class="form-row">
              <div class="field"><label>Opsi 1</label><input type="text" name="qopt[<?= $n ?>][]" value="<?= e($q['options'][0] ?? '') ?>"></div>
              <div class="field"><label>Opsi 2</label><input type="text" name="qopt[<?= $n ?>][]" value="<?= e($q['options'][1] ?? '') ?>"></div>
              <div class="field"><label>Opsi 3</label><input type="text" name="qopt[<?= $n ?>][]" value="<?= e($q['options'][2] ?? '') ?>"></div>
              <div class="field"><label>Opsi 4</label><input type="text" name="qopt[<?= $n ?>][]" value="<?= e($q['options'][3] ?? '') ?>"></div>
            </div>
            <div class="form-row">
              <div class="field"><label>Jawaban Benar / Jawaban yang Diharapkan (esai)</label><input type="text" name="qanswer[<?= $n ?>]" value="<?= e($q['answer'] ?? '') ?>" placeholder="cth: a"></div>
              <div class="field"><label>Gambar Soal (opsional)</label>
                <input type="file" name="qimg_<?= $n ?>" accept="image/jpeg,image/png,image/webp,image/gif">
                <?php if (!empty($q['img'])): ?><p class="hint">Gambar saat ini: <a href="<?= e(img_url($q['img'])) ?>" target="_blank" style="color:var(--plum);text-decoration:underline">lihat</a> (upload baru untuk mengganti)</p><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <button class="btn btn-plum" type="submit">Simpan Semua Soal</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'hof'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('trophy', 20) ?> Hall of Fame Approval</h2>
      <p class="panel-sub">Setujui/tolak karya dan beri komentar. Karya yang disetujui tampil di Hall of Fame.</p>
      <?php if (empty($arts)): ?>
        <p class="empty">Belum ada karya.</p>
      <?php else: ?>
        <div class="card-grid">
          <?php foreach ($arts as $a): ?>
            <div class="card">
              <div class="card-media"><img src="<?= e(img_url($a['image'])) ?>" alt="<?= e($a['title']) ?>" loading="lazy"></div>
              <div class="card-body">
                <h3><?= e($a['title']) ?></h3>
                <div class="card-meta">
                  <span class="m"><?= icon('user', 15) ?> <?= e($a['author']) ?> &bull; <?= e($a['kelas']) ?></span>
                  <span class="chip"><?= e($a['category']) ?></span>
                  <span class="badge <?= ($a['status'] ?? '') === 'approved' ? 'badge-approved' : (($a['status'] ?? '') === 'pending' ? 'badge-pending' : 'badge-rejected') ?>"><?= e($a['status']) ?></span>
                </div>
                <?php if (!empty($a['description'])): ?><p style="font-size:.9rem;color:var(--muted);font-weight:600"><?= e($a['description']) ?></p><?php endif; ?>
                <?php if (!empty($a['comment'])): ?><p style="font-size:.88rem;font-weight:700">Komentar admin: <?= e($a['comment']) ?></p><?php endif; ?>
                <form method="post" action="admin.php?tab=hof" style="margin-top:8px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="art_id" value="<?= e($a['id']) ?>">
                  <input type="text" name="comment" placeholder="Komentar admin (opsional)" value="<?= e($a['comment'] ?? '') ?>" style="width:100%;border:1.5px solid var(--line);border-radius:12px;padding:9px 12px;margin-bottom:8px">
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn-mini ok" type="submit" name="action" value="hof_approve">Setujui</button>
                    <button class="btn-mini danger" type="submit" name="action" value="hof_reject">Tolak</button>
                    <button class="btn-mini soft" type="submit" name="action" value="hof_delete" onclick="return confirm('Hapus karya ini?')">Hapus</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'announcements'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('mega', 20) ?> Buat Pengumuman</h2>
      <p class="panel-sub">Pengumuman muncul di Dashboard Member.</p>
      <form method="post" action="admin.php?tab=announcements">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ann_create">
        <div class="field"><label>Judul</label><input type="text" name="title" required placeholder="cth: Jadwal Latihan Minggu Ini"></div>
        <div class="field"><label>Isi</label><textarea name="body" rows="3" required placeholder="Tulis pengumuman..."></textarea></div>
        <button class="btn btn-plum" type="submit">Terbitkan Pengumuman</button>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('mega', 20) ?> Daftar Pengumuman</h2>
      <?php if (empty($ann)): ?>
        <p class="empty">Belum ada pengumuman.</p>
      <?php else: ?>
        <?php foreach ($ann as $a): ?>
          <div class="notif">
            <span class="n-ico"><?= icon('mega', 18) ?></span>
            <div style="flex:1">
              <p><?= e($a['title']) ?></p>
              <p style="font-weight:600;color:var(--muted);font-size:.9rem"><?= e($a['body']) ?></p>
              <time><?= e($a['created_at']) ?></time>
            </div>
            <form method="post" action="admin.php?tab=announcements" onsubmit="return confirm('Hapus pengumuman ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="ann_delete"><input type="hidden" name="ann_id" value="<?= e($a['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'calendar'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('calendar', 20) ?> Tambah Jadwal</h2>
      <form method="post" action="admin.php?tab=calendar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cal_add">
        <div class="form-row">
          <div class="field"><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label>Jenis</label>
            <select name="type">
              <option value="latihan">Latihan</option>
              <option value="event">Event</option>
              <option value="lomba">Lomba</option>
              <option value="ujian">Ujian</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Judul Kegiatan</label><input type="text" name="title" required placeholder="cth: Latihan Hiragana Bab 3"></div>
        <button class="btn btn-plum" type="submit">Tambah Jadwal</button>
      </form>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('calendar', 20) ?> Daftar Jadwal</h2>
      <?php if (empty($cal)): ?>
        <p class="empty">Belum ada jadwal.</p>
      <?php else: ?>
        <?php usort($cal, function ($a, $b) { return strcmp($a['date'], $b['date']); }); ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>Tanggal</th><th>Kegiatan</th><th>Jenis</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php foreach ($cal as $c): ?>
                <tr>
                  <td><b><?= e($c['date']) ?></b></td>
                  <td><?= e($c['title']) ?></td>
                  <td><span class="chip"><?= e($c['type']) ?></span></td>
                  <td>
                    <form method="post" action="admin.php?tab=calendar" onsubmit="return confirm('Hapus jadwal ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="cal_delete"><input type="hidden" name="cal_id" value="<?= e($c['id']) ?>"><button class="btn-mini danger" type="submit">Hapus</button></form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'stats'): ?>
    <div class="panel reveal visible">
      <h2><?= icon('trophy', 20) ?> Podium — Total Poin</h2>
      <p class="panel-sub">Ranking total poin keseluruhan — diperbarui setiap hari pukul 00.00 WIB dan tidak pernah di-reset. Absensi +5, Upload Karya +5 (maks 1x/hari), Event Game Top 1 +3, Top 2 +2, Top 3 +2, lainnya +1.</p>
      <?php if (empty($podiumTop)): ?>
        <p class="empty">Belum ada poin.</p>
      <?php else: ?>
        <?php foreach ($podiumTop as $i => $m): ?>
          <div class="lb-row">
            <span class="lb-rank <?= $i < 3 ? 'top' : '' ?>"><?= $i + 1 ?></span>
            <?= avatar_img($m, 34, 'avatar-sm') ?>
            <div><div class="lb-name"><?= e($m['name']) ?></div><div class="lb-sub">Kelas <?= e(kelas_jurusan($m)) ?> &bull; Total <?= (int) ($m['points'] ?? 0) ?> poin</div></div>
            <span class="lb-pts"><?= (int) ($m['points'] ?? 0) ?> poin</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="panel reveal visible">
      <h2><?= icon('chart', 20) ?> Kehadiran per Bulan</h2>
      <?php
      $perMonth = [];
      foreach ($att as $a) {
          $m = substr($a['date'] ?? '', 0, 7);
          $perMonth[$m] = ($perMonth[$m] ?? 0) + 1;
      }
      ksort($perMonth);
      $perMonth = array_slice($perMonth, -6, 6, true);
      $maxM = max(1, max($perMonth ?: [0]));
      ?>
      <?php if (empty($perMonth)): ?><p class="empty">Belum ada data kehadiran.</p>
      <?php else: foreach ($perMonth as $m => $c): ?>
        <div class="bar-row"><span class="bar-label"><?= e($m) ?></span><div class="bar-track"><i style="width:<?= round($c / $maxM * 100) ?>%"></i></div><span class="bar-val"><?= $c ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="dash-grid two">
      <div class="panel reveal visible">
        <h2><?= icon('users', 20) ?> Member Teraktif (Total Poin)</h2>
        <?php $top = leaderboard(5); $maxP = max(1, (int) ($top[0]['points'] ?? 0)); ?>
        <?php if (empty($top) || $maxP <= 0): ?><p class="empty">Belum ada poin.</p>
        <?php else: foreach ($top as $m): ?>
          <div class="bar-row"><span class="bar-label"><?= e($m['name']) ?></span><div class="bar-track"><i style="width:<?= round(((int) ($m['points'] ?? 0)) / $maxP * 100) ?>%"></i></div><span class="bar-val"><?= (int) ($m['points'] ?? 0) ?></span></div>
        <?php endforeach; endif; ?>
      </div>
      <div class="panel reveal visible">
        <h2><?= icon('torii', 20) ?> Event Paling Ramai</h2>
        <?php $evSorted = $events; usort($evSorted, function ($a, $b) { return count($b['joins'] ?? []) <=> count($a['joins'] ?? []); }); $evSorted = array_slice($evSorted, 0, 5); $maxJ = max(1, count($evSorted[0]['joins'] ?? [])); ?>
        <?php if (empty($evSorted)): ?><p class="empty">Belum ada data.</p>
        <?php else: foreach ($evSorted as $ev): ?>
          <div class="bar-row"><span class="bar-label"><?= e($ev['title']) ?></span><div class="bar-track"><i style="width:<?= round(count($ev['joins'] ?? []) / $maxJ * 100) ?>%"></i></div><span class="bar-val"><?= count($ev['joins'] ?? []) ?></span></div>
        <?php endforeach; endif; ?>
      </div>
      <div class="panel reveal visible">
        <h2><?= icon('image', 20) ?> Upload Karya per Kategori</h2>
        <?php
        $cats = ['Fan Art' => 0, 'Digital Art' => 0, 'Traditional Art' => 0, 'Manga' => 0, 'Kaligrafi Jepang' => 0, 'Desain Poster' => 0, 'Photography' => 0];
        foreach ($arts as $a) {
            $c = $a['category'] ?? '';
            if (isset($cats[$c])) { $cats[$c]++; } else { $cats[$c] = ($cats[$c] ?? 0) + 1; }
        }
        $maxC = max(1, max($cats));
        ?>
        <?php foreach ($cats as $c => $n): ?>
          <div class="bar-row"><span class="bar-label"><?= e($c) ?></span><div class="bar-track"><i style="width:<?= round($n / $maxC * 100) ?>%"></i></div><span class="bar-val"><?= $n ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="panel reveal visible">
        <h2><?= icon('gamepad', 20) ?> Aktivitas Game</h2>
        <?php $scores = load_json('game_scores.json'); $plays = count($scores); $wins = 0; foreach ($scores as $s) { if (!empty($s['win'])) $wins++; } ?>
        <div class="stat-cards" style="grid-template-columns:1fr 1fr;margin:0">
          <div class="stat-card"><span class="sc-ico"><?= icon('gamepad', 24) ?></span><div><b><?= $plays ?></b><span>Total Dimainkan</span></div></div>
          <div class="stat-card"><span class="sc-ico"><?= icon('trophy', 24) ?></span><div><b><?= $wins ?></b><span>Total Menang</span></div></div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var addBtn = document.getElementById('addQRow');
  var rowsBox = document.getElementById('qRows');
  if (addBtn && rowsBox) {
    addBtn.addEventListener('click', function () {
      var n = rowsBox.querySelectorAll('.q-row').length + 1;
      var div = document.createElement('div');
      div.className = 'form-row q-row';
      div.innerHTML = '<div class="field"><label>Soal ' + n + '</label><input type="text" name="q[]" placeholder="Soal"></div>' +
        '<div class="field"><label>Jawaban</label><input type="text" name="a[]" placeholder="Jawaban"></div>' +
        '<div class="field"><label>Pilihan (MCQ, koma)</label><input type="text" name="o[]" placeholder="Pilihan dipisah koma"></div>';
      rowsBox.appendChild(div);
    });
  }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
