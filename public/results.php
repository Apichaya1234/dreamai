<?php
/**
 * public/results.php (NEW FILE)
 * - Displays the generated dream interpretation options.
 * - Fetches data based on the session_id from the URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\Safety;

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
if (!$sessionId) { header('Location: index.php'); exit; }

try {
    $db = new DB($config['db']);
    $session = $db->one('SELECT * FROM presentation_sessions WHERE id=?', [$sessionId]);
    if (!$session) { throw new \Exception('Session not found.'); }

    $report = $db->one('SELECT user_id FROM dream_reports WHERE id=?', [$session['report_id']]);
    if (!$report || (int)$report['user_id'] !== (int)$_SESSION['user_id']) {
        throw new \Exception('Access denied.');
    }

    $slots = ['A','B','C','D'];
    $optionIds = [$session['A_option_id'], $session['B_option_id'], $session['C_option_id'], $session['D_option_id']];
    $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
    $optionsRaw = $db->all("SELECT id, content_json FROM generated_options WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)", $optionIds);
    
    $slotContent = [];
    foreach ($optionsRaw as $i => $row) {
        $slotContent[$slots[$i]] = json_decode($row['content_json'], true) ?: Safety::fallback();
    }

} catch (\Throwable $e) {
    // A simple error page
    http_response_code(500);
    echo "<h1>Error</h1><p>Could not load prediction results. " . htmlspecialchars($e->getMessage()) . "</p><a href='index.php'>Go back</a>";
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เลือกคำทำนายที่คุณชอบที่สุด — DreamAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="assets/styles.css" rel="stylesheet">
</head>
<body>
<div class="container">
    <h2>เลือกคำทำนายที่คุณชอบที่สุด</h2>
    <p class="muted small">ระบบสุ่มตำแหน่ง A–D และซ่อนที่มาของแต่ละตัวเลือก</p>
    <form method="post" action="select.php" class="mt-3">
      <input type="hidden" name="session_id" value="<?= htmlspecialchars((string)$sessionId) ?>">
      <input type="hidden" name="report_id" value="<?= htmlspecialchars((string)$session['report_id']) ?>">
      <div class="grid mt-3" id="options-grid">
        <!-- Options will be injected by JavaScript -->
      </div>

      <div class="card mt-4">
        <h3 class="mt-2">เหตุผลที่เลือก (ตอบหรือไม่ก็ได้)</h3>
        <p class="muted small">ข้อมูลส่วนนี้จะช่วยให้เราพัฒนาระบบให้ดียิ่งขึ้น</p>
        <div class="radio-inline mt-3" style="flex-direction: column; align-items: flex-start; gap: 8px;">
            <label><input type="radio" name="feedback_reason" value="ตรงกับความรู้สึก"> ตรงกับความรู้สึก/สถานการณ์จริง</label>
            <label><input type="radio" name="feedback_reason" value="ภาษาเข้าใจง่าย"> ชอบภาษาที่ใช้ อ่านเข้าใจง่าย</label>
            <label><input type="radio" name="feedback_reason" value="สร้างสรรค์"> คำทำนายมีความแปลกใหม่ น่าสนใจ</label>
            <label><input type="radio" name="feedback_reason" value="ให้คำแนะนำดี"> คำแนะนำที่ให้มานำไปใช้ได้จริง</label>
            <label><input type="radio" name="feedback_reason" value="อื่นๆ"> อื่นๆ</label>
        </div>
      </div>

      <div class="btn-group mt-4">
        <button class="btn primary" type="submit">บันทึกตัวเลือก</button>
        <a class="btn ghost" href="index.php">กลับหน้าแรก</a>
      </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const results = <?= json_encode($slotContent) ?>;
  const slots = ['A', 'B', 'C', 'D'];
  const grid = document.getElementById('options-grid');

  function createOptionCard(slot, content) {
    const tempDiv = document.createElement('div');
    const h = (text) => { tempDiv.textContent = text || ''; return tempDiv.innerHTML; };
    const title = h(content.title);
    const interp = h(content.interpretation).replace(/\n/g, '<br>');
    const lucky = content.lucky_numbers && content.lucky_numbers.length > 0 ? h(content.lucky_numbers.join(', ')) : '';

    return `
      <div class="card option">
        <div class="meta">
          <div class="badge">ตัวเลือก ${h(slot)}</div>
          ${title ? `<div class="muted small">${title}</div>` : ''}
        </div>
        <div><strong>คำทำนาย:</strong><p>${interp}</p></div>
        ${lucky ? `<div><strong>เลขนำโชค:</strong><p>${lucky}</p></div>` : ''}
        <label class="mt-2"><input type="radio" name="chosen_slot" value="${h(slot)}" required> เลือกตัวนี้</label>
      </div>`;
  }

  grid.innerHTML = slots.map(slot => results[slot] ? createOptionCard(slot, results[slot]) : '').join('');
});
</script>
</body>
</html>
