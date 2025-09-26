<?php
/**
 * public/index.php (updated)
 * - Modern UI/UX Refactor
 * - Conditionally show dream form only after profile is complete.
 * - Handle feedback messages from selection/error redirects.
 * - Show processing state on dream submission.
 * - CHANGED: Toast notifications are now prominent Modal pop-ups.
 * - ADDED: Processing modal on form submission.
 */

declare(strict_types=1);

// --- ปรับ Path ให้แม่นยำขึ้น ---
if (file_exists(__DIR__ . '/../bootstrap.php')) {
    require_once __DIR__ . '/../bootstrap.php';
} else {
    if (!class_exists('DreamAI\DB')) {
        die('Error: Cannot find bootstrap.php file.');
    }
}

use DreamAI\DB;
use DreamAI\Utils;

// --- Initialize Session and DB ---
$config = $config ?? [
    'db' => ['host' => 'localhost', 'port' => '3306', 'name' => 'dreamai', 'user' => 'root', 'pass' => ''],
    'openai' => ['api_key' => 'YOUR_API_KEY_HERE'],
    'app' => ['install_token' => 'some_secret_salt']
];

try {
    $db = new DB($config['db']);
} catch (\Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "<br>Please check your config.php and ensure the database is running.");
}

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
$flashType = 'ok';

if (isset($_GET['selection'])) {
    if ($_GET['selection'] === 'success') {
        $flash = 'บันทึกตัวเลือกของคุณเรียบร้อยแล้ว ขอบคุณสำหรับข้อมูลค่ะ!';
        $flashType = 'ok';
    } elseif ($_GET['selection'] === 'already_exists') {
        $flash = 'คุณเคยบันทึกตัวเลือกสำหรับความฝันนี้ไปแล้วค่ะ';
        $flashType = 'warn';
    }
} elseif (isset($_GET['error'])) {
    $flashType = 'warn';
    if ($_GET['error'] === 'processing_failed') {
        $flash = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง';
    } elseif ($_GET['error'] === 'invalid_selection') {
        $flash = 'ข้อมูลที่ส่งมาไม่ถูกต้อง กรุณาเลือกใหม่อีกครั้ง';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'save_profile') {
    $uid = (int)$_SESSION['user_id'];
    $gender = $_POST['gender'] ?? '';
    if (!in_array($gender, ['male', 'female', 'other'], true)) $gender = null;
    $birth = $_POST['birth_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) $birth = null;

    if ($gender && $birth) {
        $db->execStmt('UPDATE users SET consent_research=1, gender=?, birth_date=? WHERE id=?', [$gender, $birth, $uid]);
        $db->execStmt(
            'INSERT INTO research_consents(user_id, consent, consent_text_version, ip_hash) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE consent=VALUES(consent), consent_text_version=VALUES(consent_text_version), ip_hash=VALUES(ip_hash)',
            [$uid, 1, 'v2', Utils::ipHash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', (string)($config['app']['install_token'] ?? 'salt'))]
        );
        $flash = 'บันทึกโปรไฟล์และการยินยอมเรียบร้อยแล้วค่ะ';
        $flashType = 'ok';
    } else {
        $flash = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        $flashType = 'warn';
    }
}

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
        :root {
            --primary-color: #6d28d9; --primary-hover: #5b21b6; --secondary-color: #e5e7eb;
            --secondary-hover: #d1d5db; --text-color: #1f2937; --muted-text-color: #6b7280;
            --background-color: #f9fafb; --card-background: #ffffff; --success-color: #16a34a;
            --warning-color: #f97316; --border-color: #e5e7eb; --font-family: 'IBM Plex Sans Thai', sans-serif;
            --border-radius: 0.75rem; --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: var(--font-family); background-color: var(--background-color); color: var(--text-color); display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 2rem; }
        .main-container { width: 100%; max-width: 1200px; display: grid; grid-template-columns: 1fr; gap: 2rem; }
        @media (min-width: 1024px) {
            .main-container.has-two-columns { grid-template-columns: 400px 1fr; align-items: flex-start; }
            .main-container:not(.has-two-columns) { max-width: 500px; }
        }
        .card { background-color: var(--card-background); border-radius: var(--border-radius); padding: 2rem; box-shadow: var(--shadow); border: 1px solid var(--border-color); }
        .card.placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; color: var(--muted-text-color); border-style: dashed; min-height: 300px; }
        .card h1, .card h2 { margin-top: 0; color: var(--text-color); }
        .card h1 { font-size: 1.8rem; font-weight: 700; } .card h2 { font-size: 1.5rem; font-weight: 600; }
        .card .muted { color: var(--muted-text-color); font-size: 0.9rem; }
        .header { text-align: center; margin-bottom: 2rem; }
        .header h1 { font-size: 2.5rem; font-weight: 700; background: linear-gradient(to right, #6d28d9, #be185d); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .header p { font-size: 1.1rem; color: var(--muted-text-color); margin-top: -1rem; }
        label { display: block; font-weight: 500; margin-bottom: 0.5rem; } .input-group { margin-bottom: 1.25rem; }
        input[type="date"], textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.5rem; font-family: var(--font-family); font-size: 1rem; }
        textarea { min-height: 120px; resize: vertical; }
        .radio-group { display: flex; gap: 1rem; flex-wrap: wrap; }
        .radio-group label { display: flex; align-items: center; cursor: pointer; padding: 0.75rem 1.25rem; border: 1px solid var(--border-color); border-radius: 0.5rem; }
        .radio-group input[type="radio"] { display: none; }
        .radio-group input[type="radio"]:checked + span { color: var(--primary-color); font-weight: 600; }
        .radio-group label:has(input:checked) { border-color: var(--primary-color); background-color: rgba(109, 40, 217, 0.05); }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn.primary { background-color: var(--primary-color); color: white; }
        .btn.primary:disabled { background-color: var(--secondary-color); color: var(--muted-text-color); cursor: not-allowed; opacity: 0.7; }
        .btn.ghost { background-color: transparent; color: var(--muted-text-color); border: 1px solid var(--border-color); }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-size: 0.9rem; border: 1px solid transparent; }
        .alert.warn { background-color: #ffedd5; border-color: #fb923c; color: #c2410c; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 500; }
        .badge.success { background-color: #dcfce7; color: #166534; } .badge.pending { background-color: #fee2e2; color: #991b1b; }
        
        /* --- CSS สำหรับ Modal แจ้งเตือน --- */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(31, 41, 55, 0.6); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
        .modal-overlay.visible { opacity: 1; visibility: visible; }
        .modal-content { background-color: var(--card-background); border-radius: var(--border-radius); padding: 2.5rem; width: 90%; max-width: 450px; text-align: center; position: relative; transform: scale(0.95); transition: transform 0.3s ease; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1); }
        .modal-overlay.visible .modal-content { transform: scale(1); }
        .modal-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; }
        .modal-content.ok .modal-icon { background-color: #dcfce7; } .modal-content.warn .modal-icon { background-color: #ffedd5; }
        .modal-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        .modal-content.ok .modal-title { color: var(--success-color); } .modal-content.warn .modal-title { color: var(--warning-color); }
        .modal-message { color: var(--muted-text-color); margin-bottom: 2rem; line-height: 1.6; }
        .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 2rem; cursor: pointer; color: var(--muted-text-color); }
        
        /* --- ✨ START: CSS สำหรับ Modal กำลังประมวลผล --- */
        .processing-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(31, 41, 55, 0.6); z-index: 2000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
        .processing-modal-overlay.visible { opacity: 1; visibility: visible; }
        .processing-modal-content { background-color: var(--card-background); border-radius: var(--border-radius); padding: 3rem 2.5rem; width: 90%; max-width: 400px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 1rem; transform: scale(0.95); transition: transform 0.3s ease; }
        .processing-modal-overlay.visible .processing-modal-content { transform: scale(1); }
        .processing-modal-spinner { width: 48px; height: 48px; border: 5px solid var(--secondary-color); border-top-color: var(--primary-color); border-radius: 50%; animation: spin 1s linear infinite; }
        .processing-modal-title { font-size: 1.5rem; font-weight: 700; margin-top: 1rem; color: var(--text-color); }
        .processing-modal-message { color: var(--muted-text-color); margin: 0; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        /* --- ✨ END: CSS สำหรับ Modal กำลังประมวลผล --- */
    </style>
</head>
<body>

<!-- HTML ของ Modal แจ้งเตือน -->
<?php if ($flash): ?>
<div id="flash-modal-overlay" class="modal-overlay">
    <div class="modal-content <?= $flashType === 'ok' ? 'ok' : 'warn' ?>">
        <button id="flash-close" class="modal-close">&times;</button>
        <div class="modal-icon">
             <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:36px; height:36px; color: <?= $flashType === 'ok' ? 'var(--success-color)' : 'var(--warning-color)' ?>;">
              <?php if ($flashType === 'ok'): ?>
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              <?php else: ?>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
              <?php endif; ?>
            </svg>
        </div>
        <h3 class="modal-title"><?= $flashType === 'ok' ? 'สำเร็จ!' : 'โปรดทราบ' ?></h3>
        <p class="modal-message"><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <button id="modal-confirm-btn" class="btn primary" style="width: 100%;">ตกลง</button>
    </div>
</div>
<?php endif; ?>

<!-- ✨ START: HTML ของ Modal กำลังประมวลผล -->
<div id="processing-modal-overlay" class="processing-modal-overlay">
    <div class="processing-modal-content">
        <div class="processing-modal-spinner"></div>
        <h3 class="processing-modal-title">กำลังประมวลผลคำทำนาย</h3>
        <p class="processing-modal-message">กรุณารอสักครู่...</p>
    </div>
</div>
<!-- ✨ END: HTML ของ Modal กำลังประมวลผล -->

<div class="main-container <?= $profileComplete ? 'has-two-columns' : '' ?>">
    <header class="header" style="grid-column: 1 / -1;">
        <h1>🔮 DreamAI</h1>
        <p>เปลี่ยนความฝันของคุณให้เป็นคำทำนายด้วย AI</p>
    </header>

    <div class="card">
        <h2><span style="font-size: 1.2em;">👤</span> โปรไฟล์ผู้ใช้</h2>
        <p class="muted">ข้อมูลนี้จำเป็นสำหรับการใช้งาน และจะถูกใช้เพื่อการวิจัยโดยไม่ระบุตัวตน</p>
        <?php if ($needMsg): ?>
            <div class="alert warn" role="alert"><?= htmlspecialchars($needMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="index.php">
            <input type="hidden" name="__action" value="save_profile">
            <div class="input-group">
                <label>เพศ</label>
                <div class="radio-group">
                    <label><input type="radio" name="gender" value="male" <?= ($user['gender'] ?? '') === 'male' ? 'checked' : ''; ?> required><span>ชาย</span></label>
                    <label><input type="radio" name="gender" value="female" <?= ($user['gender'] ?? '') === 'female' ? 'checked' : ''; ?> required><span>หญิง</span></label>
                    <label><input type="radio" name="gender" value="other" <?= ($user['gender'] ?? '') === 'other' ? 'checked' : ''; ?> required><span>อื่นๆ</span></label>
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
            <div class="btn-group">
                <button class="btn primary" type="submit"><?= $profileComplete ? 'อัปเดตโปรไฟล์' : 'บันทึกและยินยอม' ?></button>
            </div>
             <p class="muted" style="font-size: 0.8rem; margin-top: 1rem;">รหัสผู้ใช้: <code><?= htmlspecialchars($_SESSION['anon_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
        </form>
    </div>

    <?php if ($profileComplete): ?>
        <div class="card">
            <h2><span style="font-size: 1.2em;">✨</span> ใส่ความฝันของคุณ</h2>
            <p class="muted">พิมพ์ความฝันของคุณ เพื่อเล่าความฝัน</p>
            <form id="dreamForm" method="post" action="generate.php">
                <div class="input-group">
                    <label for="dream_text">เรื่องราวในความฝัน</label>
                    <textarea id="dream_text" name="dream_text" placeholder="เช่น ฝันว่ากำลังวิ่งอยู่ในทุ่งดอกไม้ที่ไม่มีที่สิ้นสุด..." maxlength="5000" rows="5"></textarea>
                </div>
                <!--div class="input-group">
                    <label>เล่าความฝันด้วยเสียง</label>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <button type="button" class="btn ghost" id="btnMic">🎙️ เริ่มอัดเสียง</button>
                        <span class="muted" id="micStatus">ยังไม่ได้อัดเสียง</span>
                    </div>
                </div-->
                <input type="hidden" name="audio_b64" id="audio_b64">
                <input type="hidden" name="audio_mime" id="audio_mime" value="audio/webm">
                <div class="btn-group">
                    <button class="btn primary" type="submit" <?= $hasApiKey ? '' : 'disabled' ?>>ทำนายฝัน</button>
                    <a class="btn ghost" href="index.php">ล้างข้อมูล</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="card placeholder">
             <h2 style="font-size: 3rem; margin: 0;">🔮✨</h2>
             <p style="font-size: 1.1rem; font-weight: 500; margin-top: 1.5rem;">กรุณากรอกข้อมูลโปรไฟล์ให้ครบถ้วน</p>
             <p class="muted">เพื่อปลดล็อกฟีเจอร์ทำนายฝัน</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Logic สำหรับ Modal แจ้งเตือน ---
    const modalOverlay = document.getElementById('flash-modal-overlay');
    if (modalOverlay) {
        const hideModal = () => modalOverlay.classList.remove('visible');
        setTimeout(() => { modalOverlay.classList.add('visible'); }, 100);
        const closeButton = document.getElementById('flash-close');
        const confirmButton = document.getElementById('modal-confirm-btn');
        if (closeButton) closeButton.addEventListener('click', hideModal);
        if (confirmButton) confirmButton.addEventListener('click', hideModal);
        modalOverlay.addEventListener('click', (event) => { if (event.target === modalOverlay) hideModal(); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modalOverlay.classList.contains('visible')) hideModal(); });
    }

    // --- ✨ START: Logic สำหรับ Modal กำลังประมวลผล ---
    const dreamForm = document.getElementById('dreamForm');
    const processingModal = document.getElementById('processing-modal-overlay');
    if (dreamForm && processingModal) {
        dreamForm.addEventListener('submit', function(event) {
            const dreamText = document.getElementById('dream_text').value.trim();
            const audioB64 = document.getElementById('audio_b64').value.trim();
            if (dreamText === '' && audioB64 === '') {
                alert('กรุณาเล่าความฝันของคุณก่อนค่ะ');
                event.preventDefault();
                return;
            }
            // แสดง Modal
            processingModal.classList.add('visible');
        });
    }
    // --- ✨ END: Logic สำหรับ Modal กำลังประมวลผล ---

    // --- Logic สำหรับ Mic Recorder ---
    const btnMic = document.getElementById('btnMic');
    if(btnMic) {
        const micStatus = document.getElementById('micStatus');
        const audioB64 = document.getElementById('audio_b64');
        const audioMime = document.getElementById('audio_mime');
        let mediaRecorder, chunks = [], isRecording = false;

        async function startRecording() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('เบราว์เซอร์ของคุณไม่รองรับการอัดเสียงค่ะ'); return; }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
                audioMime.value = mimeType;
                mediaRecorder = new MediaRecorder(stream, { mimeType });
                chunks = [];
                mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) chunks.push(e.data); };
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
    }
});
</script>
</body>
</html>

