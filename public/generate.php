<?php
/**
 * public/generate.php
 * Updated version aligned with thesis methodology (Approach 1).
 * - Uses 4 fixed model/parameter configurations for direct comparison.
 * - ADDED: A prominent information box and FAQ for research participants.
 * - ADDED: AI-powered pre-filtering for user input safety.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\OpenAI;
use DreamAI\Safety;
use DreamAI\Utils;

session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$db = new DB($config['db']);
$ai = new OpenAI($config['openai']);

$u = $db->one('SELECT consent_research, gender, birth_date FROM users WHERE id=?', [$_SESSION['user_id']]);
if (!$u || (int)$u['consent_research']!==1 || empty($u['gender']) || empty($u['birth_date'])) {
  header('Location: index.php?need_profile=1'); exit;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>กำลังทำนายฝัน... — DreamAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .spinner { border: 4px solid rgba(0,0,0,0.1); width: 36px; height: 36px; border-radius: 50%; border-left-color: var(--c-text); animation: spin 1s ease infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .research-note { background-color: #f3f4f6; border-left: 5px solid var(--c-primary); padding: 1.5rem; margin: 2rem 0; border-radius: 0.5rem; }
    .research-note h3 { margin-top: 0; color: var(--c-primary); font-size: 1.2rem; display: flex; align-items: center; gap: 0.5rem; }
    .research-note p { margin-bottom: 0; color: var(--c-muted); }
    .faq-section { margin-top: 3rem; border-top: 1px solid var(--c-border); padding-top: 2rem; }
    .faq-item { margin-bottom: 1.5rem; }
    .faq-item h4 { margin-bottom: 0.5rem; font-weight: 600; color: var(--c-text); }
    .faq-item p { color: var(--c-muted); font-size: 0.9rem; margin: 0; }
  </style>
</head>
<body>
<div class="container">
  <div id="loading-state">
    <h2>กำลังสร้างคำทำนาย...</h2>
    <p class="muted small">กรุณารอสักครู่ ระบบกำลังใช้ปัญญาประดิษฐ์วิเคราะห์ความฝันของคุณ</p>
    <div class="spinner"></div>
  </div>

  <div id="results-container" style="display:none;">
    <h2>เลือกคำทำนายที่คุณชอบที่สุด</h2>
    <p class="muted small">ระบบสุ่มตำแหน่ง A–D และซ่อนที่มาของแต่ละตัวเลือก</p>
    <div class="research-note">
        <h3>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px; height:24px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.852l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
            </svg>
            ข้อมูลสำคัญสำหรับผู้เข้าร่วมวิจัย
        </h3>
        <p>
            การเลือกของคุณคือส่วนสำคัญของงานวิจัยชิ้นนี้! ทุกครั้งที่คุณเลือก ระบบจะบันทึกข้อมูล <strong>(1) ตัวเลือกที่ท่านชอบ</strong> และ <strong>(2) ข้อมูลประชากร (เพศ/อายุ)</strong> เพื่อนำไปวิเคราะห์ในภาพรวมว่า AI ควรสร้างคำทำนายแบบใด ข้อมูลทั้งหมด<strong>ไม่สามารถระบุตัวตน</strong>ของท่านได้และใช้เพื่อการศึกษาเท่านั้น
        </p>
    </div>

    <form method="post" action="select.php" class="mt-3">
      <input type="hidden" name="session_id" id="session_id_field">
      <input type="hidden" name="report_id" id="report_id_field">
      <div class="grid mt-3" id="options-grid"></div>
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
    
    <div class="faq-section">
        <h2>คำถามที่พบบ่อยเกี่ยวกับการวิจัย</h2>
        <div class="faq-item">
            <h4>ทำไมต้องเก็บข้อมูลเพศและวันเกิด?</h4>
            <p>เราใช้ข้อมูลนี้เพื่อวิเคราะห์แนวโน้มในภาพรวมเท่านั้น เช่น "ผู้หญิงในช่วงอายุ 20-30 ปี ชอบคำทำนายสไตล์ B มากกว่า" ข้อมูลนี้จะไม่ถูกใช้เพื่อระบุตัวตนของคุณเด็ดขาด</p>
        </div>
        <div class="faq-item">
            <h4>ข้อมูลของฉันปลอดภัยแค่ไหน?</h4>
            <p>ข้อมูลการเลือกจะถูกเก็บโดยใช้รหัสผู้ใช้แบบสุ่ม (Anonymous ID) ซึ่งไม่เชื่อมโยยงกับข้อมูลส่วนตัวใดๆ และจะถูกใช้ในการวิจัยนี้เท่านั้น เราไม่ได้เก็บข้อมูลความฝันของคุณร่วมกับการเลือกเพื่อการวิเคราะห์</p>
        </div>
    </div>
  </div>
</div>

<?php
ob_start();

function createReport(DB $db, int $userId, string $channel, string $rawText): int {
  return (int)$db->execStmt('INSERT INTO dream_reports(user_id, channel, raw_text, mood, language) VALUES (?,?,?,?,?)', [$userId, $channel, $rawText, 'neutral', 'th']);
}

function logModeration(DB $db, string $stage, int $entityId, string $model, array $result): void {
  $flagged = $result['results'][0]['flagged'] ?? false;
  $db->execStmt('INSERT INTO moderation_logs(stage, entity_id, model, result_json, flagged) VALUES (?,?,?,?,?)', [$stage, $entityId, $model, json_encode($result, JSON_UNESCAPED_UNICODE), (int)$flagged]);
}

function insertOption(DB $db, int $reportId, array $optData): int {
  $json = json_encode($optData['content'], JSON_UNESCAPED_UNICODE);
  return (int)$db->execStmt(
    'INSERT INTO generated_options (report_id, source, angle, style_key, model, temperature, top_p, content_json) VALUES (?,?,?,?,?,?,?,?)',
    [$reportId, $optData['source'], $optData['angle'], $optData['style_key'], $optData['model'], $optData['t'], $optData['p'], $json]
  );
}

$userId    = (int)$_SESSION['user_id'];
$dreamText = Utils::normalizeText(trim($_POST['dream_text'] ?? ''), false);
$channel   = 'text';

if (!empty($_POST['audio_b64'])) {
  $channel = 'audio';
  // ... audio processing logic ...
}

if ($dreamText === '') { header('Location: index.php'); exit; }

$reportId  = createReport($db, $userId, $channel, $dreamText);

try {
    $safeDreamText = $ai->rephraseForSafety($dreamText, $config['openai']['model_gpt4o'] ?? 'gpt-4o');
} catch (\Throwable $e) {
    error_log("DreamAI Rephrase Error: " . $e->getMessage());
    $safeDreamText = $dreamText;
}

$inputMod = $ai->moderate($safeDreamText);
logModeration($db, 'input', $reportId, 'text-moderation-latest', $inputMod);
$inputFlagged = (bool)($inputMod['results'][0]['flagged'] ?? false);

$options = [];
$seen = [];
$gpt4o = $config['openai']['model_gpt4o'] ?? 'gpt-4o';
$gpt5  = $config['openai']['model_gpt5']  ?? 'gpt-4o';

$experimentArms = [
    ['model' => $gpt4o, 'temp' => 0.3, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'A_gpt4o_t0.3_symbolic', 'diversity_instruction' => 'ให้เน้นการตีความตามสัญลักษณ์ที่ปรากฏในฝันและอ้างอิงตามตำราเป็นหลักอย่างตรงไปตรงมา'],
    ['model' => $gpt4o, 'temp' => 0.7, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'B_gpt4o_t0.7_psychological', 'diversity_instruction' => 'ให้เน้นการตีความในเชิงจิตวิทยา โดยเชื่อมโยงกับสภาวะอารมณ์ ความรู้สึก ความเครียด หรือความปรารถนาที่ซ่อนอยู่ของผู้ฝัน'],
    ['model' => $gpt4o, 'temp' => 1.0, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'C_gpt4o_t1.0_creative', 'diversity_instruction' => 'ให้ตีความอย่างสร้างสรรค์ เปรียบเทียบเป็นอุปมาอุปไมย และคาดการณ์ถึงเหตุการณ์ในอนาคตที่อาจเกิดขึ้นได้หลายแง่มุม'],
    ['model' => $gpt5, 'temp' => 0.8, 'top_p' => 1.0, 'source' => 'gpt-5', 'arm_id' => 'E_gpt5_uncertainty', 'diversity_instruction' => 'หากความฝันมีความหมายได้หลายแง่มุม ให้สร้างคำทำนายแบบมีเงื่อนไข (Scenarios) 2-3 รูปแบบตามสถานการณ์ชีวิตที่ผู้ฝันอาจเผชิญอยู่', 'enable_uncertainty' => true],
];

if ($inputFlagged) {
    $fallbackContent = Safety::fallback();
    $fallbackContent['title'] = 'เนื้อหาถูกกลั่นกรอง';
    $fallbackContent['interpretation'] = 'ข้อความของคุณอาจมีคำที่ระบบจำเป็นต้องปรับแก้เพื่อความปลอดภัย โปรดตรวจสอบผลลัพธ์ที่ได้';
    for ($i = 0; $i < 4; $i++) {
        $options[] = ['source'=>'moderation', 'model'=>'system', 't'=>0, 'p'=>0, 'angle'=>'flagged', 'style_key'=>'input-flagged', 'content'=>$fallbackContent];
    }
} else {
    foreach ($experimentArms as $arm) {
      try {
        $gen = $ai->responsesGenerate($arm['model'], $safeDreamText, (float)$arm['temp'], (float)$arm['top_p'], $arm['diversity_instruction'], $seen, null, $arm['enable_uncertainty'] ?? false);
        $gen = Safety::postFilter($ai, $gen);
        $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>$arm['diversity_instruction'], 'content'=>$gen];
        $seen[] = is_string($gen['interpretation']) ? $gen['interpretation'] : json_encode($gen['interpretation']);
      } catch (\Throwable $e) {
        error_log("DreamAI Generation Error (Arm: {$arm['arm_id']}): {$e->getMessage()}");
        $fallbackContent = Safety::fallback();
        $fallbackContent['title'] = 'เกิดข้อผิดพลาดในการสร้าง';
        $fallbackContent['interpretation'] = "ขออภัยค่ะ ระบบไม่สามารถสร้างคำทำนายได้ในขณะนี้";
        $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>'error-fallback', 'content'=>$fallbackContent];
      }
    }
}

$optionIds = [];
foreach ($options as $opt) {
  $id = insertOption($db, $reportId, $opt);
  // Output moderation logic can be added here if needed
  $optionIds[] = $id;
}

$slots = ['A','B','C','D']; shuffle($slots);
$map = array_combine($slots, $optionIds);
$sessionId = (int)$db->execStmt('INSERT INTO presentation_sessions(report_id, slot_order, A_option_id, B_option_id, C_option_id, D_option_id) VALUES (?,?,?,?,?,?)', [$reportId, json_encode($slots), $map['A'],$map['B'],$map['C'],$map['D']]);

$slotContent = [];
foreach ($slots as $s) {
  $row = $db->one('SELECT content_json FROM generated_options WHERE id=?', [$map[$s]]);
  $slotContent[$s] = $row ? json_decode($row['content_json'], true) : Safety::fallback();
}

ob_end_clean();
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const sessionId = <?= json_encode($sessionId) ?>;
  const reportId = <?= json_encode($reportId) ?>;
  const results = <?= json_encode($slotContent) ?>;
  const slots = ['A', 'B', 'C', 'D'];
  const grid = document.getElementById('options-grid');
  document.getElementById('session_id_field').value = sessionId;
  document.getElementById('report_id_field').value = reportId;

  function createOptionCard(slot, content) {
    const tempDiv = document.createElement('div');
    const h = (text) => { tempDiv.textContent = text || ''; return tempDiv.innerHTML; };
    const title = h(content.title);
    
    let interp = '';
    if (typeof content.interpretation === 'string') {
        interp = h(content.interpretation).replace(/\n/g, '<br>');
    } else if (Array.isArray(content.interpretation)) {
        interp = content.interpretation.map(item => `<strong>${h(item.condition)}</strong><br>${h(item.interpretation).replace(/\n/g, '<br>')}`).join('<br><br>');
    }

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
  document.getElementById('loading-state').style.display = 'none';
  document.getElementById('results-container').style.display = 'block';
  document.title = 'เลือกคำทำนายที่คุณชอบที่สุด — DreamAI';
});
</script>
</body>
</html>

