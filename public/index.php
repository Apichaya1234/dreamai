<?php
/**
 * public/index.php (updated)
 * - Modern UI/UX Refactor
 * - Conditionally show dream form only after profile is complete.
 */

declare(strict_types=1);

// Assuming bootstrap.php is in the parent directory
require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\Utils;

// --- Initialize Session and DB ---
// Faking config for demonstration if not set
$config = $config ?? [
    'db' => ['dsn' => 'sqlite::memory:'],
    'openai' => ['api_key' => 'YOUR_API_KEY_HERE'], // Simulate having an API key
    'app' => ['install_token' => 'some_secret_salt']
];


$db = new DB($config['db']);
// In-memory SQLite needs table creation for demo
$db->execStmt("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, anon_id TEXT, consent_research INTEGER, gender TEXT, birth_date TEXT);");
$db->execStmt("CREATE TABLE IF NOT EXISTS research_consents (id INTEGER PRIMARY KEY, user_id INTEGER, consent INTEGER, consent_text_version TEXT, ip_hash TEXT);");


@session_start();


if (!isset($_SESSION['anon_id'])) {
    $_SESSION['anon_id'] = Utils::uuidv4();
}

if (!isset($_SESSION['user_id'])) {
    $row = $db->one('SELECT id FROM users WHERE anon_id=?', [$_SESSION['anon_id']]);
    if ($row) {
        $_SESSION['user_id'] = (int)$row['id'];
    } else {
        $uid = $db->execStmt('INSERT INTO users(anon_id, consent_research) VALUES (?,0)', [$_SESSION['anon_id']]);
        $_SESSION['user_id'] = (int)$uid;
    }
}

$flash = null;

// --- Save Profile Logic (consent forced ON) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'save_profile') {
    $uid = (int)$_SESSION['user_id'];

    $gender = $_POST['gender'] ?? '';
    if (!in_array($gender, ['male', 'female', 'other'], true)) {
        $gender = null;
    }

    $birth = $_POST['birth_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) {
        $birth = null;
    }

    if ($gender && $birth) {
        // Force consent = 1
        $db->execStmt('UPDATE users SET consent_research=1, gender=?, birth_date=? WHERE id=?', [$gender, $birth, $uid]);
        $db->execStmt(
            'INSERT INTO research_consents(user_id, consent, consent_text_version, ip_hash) VALUES (?,?,?,?)',
            [$uid, 1, 'v2', Utils::ipHash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', (string)($config['app']['install_token'] ?? 'salt'))]
        );
        $flash = 'บันทึกโปรไฟล์และการยินยอมเรียบร้อยแล้วค่ะ';
    } else {
        $flash = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    }
}

// --- Current State ---
$user = $db->one('SELECT consent_research, gender, birth_date FROM users WHERE id=?', [$_SESSION['user_id']]);
$profileComplete = !empty($user['gender']) && !empty($user['birth_date']) && (int)($user['consent_research'] ?? 0) === 1;
$needMsg = isset($_GET['need_profile']) && !$profileComplete ? 'กรุณากรอกข้อมูลโปรไฟล์ให้ครบถ้วนก่อนเริ่มทำนายฝัน' : null;
$hasApiKey = !empty($config['openai']['api_key']);

?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>DreamAI — ทำนายฝันด้วย AI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Modern UI Reset & Variables --- */
        :root {
            --primary-color: #6d28d9;
            --primary-hover: #5b21b6;
            --secondary-color: #e5e7eb;
            --secondary-hover: #d1d5db;
            --text-color: #1f2937;
            --muted-text-color: #6b7280;
            --background-color: #f9fafb;
            --card-background: #ffffff;
            --success-color: #16a34a;
            --warning-color: #f97316;
            --border-color: #e5e7eb;
            --font-family: 'IBM Plex Sans Thai', sans-serif;
            --border-radius: 0.75rem;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: var(--font-family);
            background-color: var(--background-color);
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 2rem;
        }

        /* --- Layout --- */
        .main-container {
            width: 100%;
            max-width: 1200px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        @media (min-width: 1024px) {
            .main-container.has-two-columns {
                grid-template-columns: 400px 1fr;
                align-items: flex-start;
            }
             .main-container:not(.has-two-columns) {
                max-width: 500px;
            }
        }

        /* --- Card Component --- */
        .card {
            background-color: var(--card-background);
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.2s ease-in-out;
        }
        .card.placeholder {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: var(--muted-text-color);
            border-style: dashed;
            min-height: 300px;
        }
        .card h1, .card h2 {
            margin-top: 0;
            color: var(--text-color);
        }
        .card h1 { font-size: 1.8rem; font-weight: 700; }
        .card h2 { font-size: 1.5rem; font-weight: 600; }
        .card .muted { color: var(--muted-text-color); font-size: 0.9rem; }

        /* --- Header & Title --- */
        .header { text-align: center; margin-bottom: 2rem; }
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(to right, #6d28d9, #be185d);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header p { font-size: 1.1rem; color: var(--muted-text-color); margin-top: -1rem; }

        /* --- Form Elements --- */
        label { display: block; font-weight: 500; margin-bottom: 0.5rem; }
        .input-group { margin-bottom: 1.25rem; }
        input[type="date"], textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-family: var(--font-family);
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input[type="date"]:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.2);
        }
        textarea { min-height: 120px; resize: vertical; }

        /* --- Custom Radio Buttons --- */
        .radio-group { display: flex; gap: 1rem; flex-wrap: wrap; }
        .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 0.75rem 1.25rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .radio-group input[type="radio"] { display: none; }
        .radio-group input[type="radio"]:checked + span {
             color: var(--primary-color);
             font-weight: 600;
        }
        .radio-group label:has(input:checked) {
            border-color: var(--primary-color);
            background-color: rgba(109, 40, 217, 0.05);
        }

        /* --- Buttons --- */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            font-family: var(--font-family);
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn.primary { background-color: var(--primary-color); color: white; }
        .btn.primary:hover { background-color: var(--primary-hover); }
        .btn.primary:disabled { background-color: var(--secondary-color); color: var(--muted-text-color); cursor: not-allowed; }
        .btn.ghost { background-color: transparent; color: var(--muted-text-color); border: 1px solid var(--border-color); }
        .btn.ghost:hover { background-color: var(--secondary-hover); color: var(--text-color); }
        .btn-group { display: flex; gap: 1rem; margin-top: 1.5rem; }

        /* --- Alerts & Badges --- */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            border: 1px solid transparent;
        }
        .alert.ok { background-color: #dcfce7; border-color: #4ade80; color: #15803d; }
        .alert.warn { background-color: #ffedd5; border-color: #fb923c; color: #c2410c; }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .badge.success { background-color: #dcfce7; color: #166534; }
        .badge.pending { background-color: #fee2e2; color: #991b1b; }

        /* --- Helper Classes --- */
        .mt-2 { margin-top: 1rem; }
    </style>
</head>
<body>

<div class="main-container <?= $profileComplete ? 'has-two-columns' : '' ?>">

    <header class="header" style="grid-column: 1 / -1;">
        <h1>🔮 DreamAI</h1>
        <p>เปลี่ยนความฝันของคุณให้เป็นคำทำนายด้วย AI</p>
    </header>

    <!-- Profile & Consent Card -->
    <div class="card">
        <h2><span style="font-size: 1.2em;">👤</span> โปรไฟล์ผู้ใช้</h2>
        <p class="muted">ข้อมูลนี้จำเป็นสำหรับการใช้งาน และจะถูกใช้เพื่อการวิจัยโดยไม่ระบุตัวตน</p>

        <?php if ($flash): ?>
            <div class="alert <?= str_contains($flash, 'ครบถ้วน') ? 'warn' : 'ok' ?>" role="status"><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($needMsg): ?>
            <div class="alert warn" role="alert"><?= htmlspecialchars($needMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <input type="hidden" name="__action" value="save_profile">
            <div class="input-group">
                <label for="gender">เพศ</label>
                <div class="radio-group" id="gender">
                    <label>
                        <input type="radio" name="gender" value="male" <?= ($user['gender'] ?? '') === 'male' ? 'checked' : ''; ?> required>
                        <span>ชาย</span>
                    </label>
                    <label>
                        <input type="radio" name="gender" value="female" <?= ($user['gender'] ?? '') === 'female' ? 'checked' : ''; ?> required>
                        <span>หญิง</span>
                    </label>
                    <label>
                        <input type="radio" name="gender" value="other" <?= ($user['gender'] ?? '') === 'other' ? 'checked' : ''; ?> required>
                        <span>อื่นๆ</span>
                    </label>
                </div>
            </div>
            <div class="input-group">
                <label for="birth_date">วันเกิด (ค.ศ.)</label>
                <input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars((string)($user['birth_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>
            <div class="input-group">
                <label>สถานะการยินยอม</label>
                <?= $profileComplete ? '<span class="badge success">ยินยอมและบันทึกข้อมูลแล้ว</span>' : '<span class="badge pending">ยังไม่สมบูรณ์</span>' ?>
            </div>
            <input type="hidden" name="consent" value="1">
            <div class="btn-group">
                <button class="btn primary" type="submit"><?= $profileComplete ? 'อัปเดตโปรไฟล์' : 'บันทึกและยินยอม' ?></button>
            </div>
             <p class="muted mt-2" style="font-size: 0.8rem;">รหัสผู้ใช้: <code><?= htmlspecialchars($_SESSION['anon_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
        </form>
    </div>

    <!-- Conditional Dream Interpreter Card -->
    <?php if ($profileComplete): ?>
        <div class="card">
            <h2><span style="font-size: 1.2em;">✨</span> ใส่ความฝันของคุณ</h2>
            <p class="muted">พิมพ์ความฝันของคุณ หรือกดปุ่ม "ไมโครโฟน" เพื่อเล่าความฝัน</p>

            <?php if (!$hasApiKey): ?>
                <div class="alert warn" role="alert"><strong>ข้อผิดพลาด:</strong> ยังไม่ได้ตั้งค่า OPENAI_API_KEY ในระบบ</div>
            <?php endif; ?>

            <form id="dreamForm" method="post" action="generate.php">
                <div class="input-group">
                    <label for="dream_text">เรื่องราวในความฝัน</label>
                    <textarea id="dream_text" name="dream_text" placeholder="เช่น ฝันว่ากำลังวิ่งอยู่ในทุ่งดอกไม้ที่ไม่มีที่สิ้นสุด..." maxlength="5000" rows="5"></textarea>
                </div>
                <div class="input-group">
                    <label>เล่าความฝันด้วยเสียง</label>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <button type="button" class="btn ghost" id="btnMic">🎙️ เริ่มอัดเสียง</button>
                        <span class="muted" id="micStatus">ยังไม่ได้อัดเสียง</span>
                    </div>
                </div>
                <input type="hidden" name="audio_b64" id="audio_b64">
                <input type="hidden" name="audio_mime" id="audio_mime" value="audio/webm">
                <div class="btn-group">
                    <button class="btn primary" type="submit" <?= $hasApiKey ? '' : 'disabled' ?>>ทำนายฝัน</button>
                    <a class="btn ghost" href="index.php">ล้างข้อมูล</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Placeholder card shown when profile is not complete -->
        <div class="card placeholder">
             <h2 style="font-size: 3rem; margin: 0;">🔮✨</h2>
             <p style="font-size: 1.1rem; font-weight: 500; margin-top: 1.5rem;">กรุณากรอกข้อมูลโปรไฟล์ให้ครบถ้วน</p>
             <p class="muted">เพื่อปลดล็อกฟีเจอร์ทำนายฝัน</p>
        </div>
    <?php endif; ?>

</div>

<script>
(() => {
    // This script will only be relevant if the dream form is visible.
    const dreamForm = document.getElementById('dreamForm');
    if (!dreamForm) return;

    // --- Mic Recorder Logic ---
    const btnMic = document.getElementById('btnMic');
    const micStatus = document.getElementById('micStatus');
    const audioB64 = document.getElementById('audio_b64');
    const audioMime = document.getElementById('audio_mime');

    let mediaRecorder, chunks = [], isRecording = false;

    async function startRecording() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('เบราว์เซอร์ของคุณไม่รองรับการอัดเสียงค่ะ');
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
            audioMime.value = mimeType;
            
            mediaRecorder = new MediaRecorder(stream, { mimeType });
            chunks = [];
            mediaRecorder.ondataavailable = e => {
                if (e.data && e.data.size > 0) chunks.push(e.data);
            };
            
            mediaRecorder.onstop = () => {
                const blob = new Blob(chunks, { type: mimeType });
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64String = (reader.result || '').toString().split(',')[1] || '';
                    audioB64.value = base64String;
                    micStatus.textContent = `อัดเสียงแล้ว (${Math.round(blob.size / 1024)} KB)`;
                    stream.getTracks().forEach(track => track.stop());
                };
                reader.readAsDataURL(blob);
            };
            
            mediaRecorder.start();
            isRecording = true;
            btnMic.innerHTML = '⏹️ หยุดอัดเสียง';
            micStatus.textContent = 'กำลังรับฟัง...';
        } catch (err) {
            console.error("Error starting recording:", err);
            alert('ไม่สามารถเข้าถึงไมโครโฟนได้ กรุณาตรวจสอบการอนุญาต');
        }
    }

    function stopRecording() {
        if (mediaRecorder && isRecording) {
            mediaRecorder.stop();
            isRecording = false;
            btnMic.innerHTML = '🎙️ เริ่มอัดเสียง';
        }
    }

    btnMic.addEventListener('click', () => isRecording ? stopRecording() : startRecording());
})();
</script>
</body>
</html>
