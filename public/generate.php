<?php
/**
 * public/generate.php
 * Updated version aligned with thesis methodology (Approach 1).
 * - Removes "angle" complexity.
 * - Uses 4 fixed model/parameter configurations for direct comparison.
 * - Keeps Loading State UX and feedback form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\OpenAI;
use DreamAI\Safety;
use DreamAI\Utils;

// --- Start session and validation ---
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$db = new DB($config['db']);
$ai = new OpenAI($config['openai']);

$u = $db->one('SELECT consent_research, gender, birth_date FROM users WHERE id=?', [$_SESSION['user_id']]);
if (!$u || (int)$u['consent_research']!==1 || empty($u['gender']) || empty($u['birth_date'])) {
  header('Location: index.php?need_profile=1'); exit;
}

// --- Start Page Render (for Loading State) ---
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>กำลังทำนายฝัน... — DreamAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    .spinner {
      border: 4px solid rgba(0, 0, 0, 0.1);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border-left-color: var(--c-text);
      animation: spin 1s ease infinite;
      margin: 20px auto;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
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
    <form method="post" action="select.php" class="mt-3">
      <input type="hidden" name="session_id" id="session_id_field">
      <input type="hidden" name="report_id" id="report_id_field">
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
</div>

<?php
// --- PHP Processing Block ---
ob_start();

function createReport(DB $db, int $userId, string $channel, string $rawText, string $mood='neutral'): int {
  return (int)$db->execStmt('INSERT INTO dream_reports(user_id, channel, raw_text, mood, language) VALUES (?,?,?,?,?)', [$userId, $channel, $rawText, $mood, 'th']);
}

function logModeration(DB $db, string $stage, int $entityId, string $model, array $result): void {
  $db->execStmt('INSERT INTO moderation_logs(stage, entity_id, model, result_json, flagged) VALUES (?,?,?,?,?)', [$stage, $entityId, $model, json_encode($result, JSON_UNESCAPED_UNICODE), (int)($result['results'][0]['flagged'] ?? 0)]);
}

function insertOption(DB $db, int $reportId, string $source, ?string $model, ?float $t, ?float $p, array $content, string $label='pending', ?string $angle=null, ?string $styleKey=null): int {
  $json = json_encode($content, JSON_UNESCAPED_UNICODE);
  // Using 'angle' field to store the arm identifier for easier analysis
  return (int)$db->execStmt(
    'INSERT INTO generated_options (report_id, source, angle, style_key, diversity_method, model, temperature, top_p, prompt_version, content_json, risk_score, moderation_label) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    [$reportId, $source, $angle, $styleKey, 'fixed-arm', $model, $t, $p, 'v4-thesis-aligned', $json, 0.0, $label]
  );
}

// 1. Read inputs
$userId    = (int)$_SESSION['user_id'];
$dreamText = Utils::normalizeText(trim($_POST['dream_text'] ?? ''), false);
$channel   = 'text';
$reportId  = createReport($db, $userId, $channel, '', 'neutral');

if (!empty($_POST['audio_b64'])) {
  $channel = 'audio';
  $tmp = tempnam(sys_get_temp_dir(), 'dreamai_');
  file_put_contents($tmp, base64_decode($_POST['audio_b64']));
  try {
    $res = $ai->transcribe($tmp, $config['openai']['transcribe'] ?? 'gpt-4o-transcribe');
    $stt = trim((string)($res['text'] ?? ''));
    if ($stt !== '') $dreamText = $dreamText ? ($dreamText . "\n" . $stt) : $stt;
    $db->execStmt('INSERT INTO dream_report_media(report_id, transcript, stt_model) VALUES (?,?,?)', [$reportId, $stt, $config['openai']['transcribe'] ?? 'gpt-4o-transcribe']);
  } catch (\Throwable $e) { $db->execStmt('INSERT INTO dream_report_media(report_id, transcript, stt_model) VALUES (?,?,?)', [$reportId, null, 'transcribe_error']); }
  finally { @unlink($tmp); }
}

if ($dreamText === '') {
  header('Location: index.php'); exit;
}

$dreamMood = 'neutral';
try { $dreamMood = $ai->classifyMood($dreamText); } catch (\Throwable $e) {}
$db->execStmt('UPDATE dream_reports SET raw_text=?, mood=?, channel=? WHERE id=?', [$dreamText, $dreamMood, $channel, $reportId]);

$retrieved_context = '';
try {
  $match = $db->one('SELECT l.* FROM dream_lexicon l JOIN dream_synonyms s ON s.lexicon_id = l.id WHERE ? LIKE CONCAT("%", s.term, "%") UNION SELECT l.* FROM dream_lexicon l WHERE ? LIKE CONCAT("%", l.lemma, "%") LIMIT 1', [$dreamText, $dreamText]);
  if ($match) {
      $db->execStmt('INSERT INTO report_matches(report_id, lexicon_id, match_type, confidence) VALUES (?,?,?,?)', [$reportId, (int)$match['id'], 'keyword', 0.9]);
      $context_parts = [];
      if (!empty($match['description'])) $context_parts[] = $match['description'];
      if ($dreamMood === 'negative' && !empty($match['negative_interpretation'])) {
          $context_parts[] = "คำทำนายด้านลบ: " . $match['negative_interpretation'];
      } elseif (!empty($match['positive_interpretation'])) {
          $context_parts[] = "คำทำนายด้านบวก: " . $match['positive_interpretation'];
      }
      if ($context_parts) $retrieved_context = "ข้อมูลอ้างอิงจากตำรา: " . implode(' | ', $context_parts);
  }
} catch (\Throwable $e) {}

$inputMod = $ai->moderate($dreamText);
logModeration($db, 'input', $reportId, 'omni-moderation-latest', $inputMod);
$inputFlagged = (bool)($inputMod['results'][0]['flagged'] ?? false);

// --- MODIFIED: Thesis-aligned Experiment Setup ---
$options = [];
$seen = [];

$gpt4o = $config['openai']['model_gpt4o'] ?? 'gpt-4o';
$gpt5  = $config['openai']['model_gpt5']  ?? 'gpt-5'; // Fallback just in case


$experimentArms = [
    [
        'model' => $gpt4o, 'temp' => 0.3, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'A_gpt4o_t0.3_symbolic',
        'diversity_instruction' => 'ให้เน้นการตีความตามสัญลักษณ์ที่ปรากฏในฝันและอ้างอิงตามตำราเป็นหลักอย่างตรงไปตรงมา'
    ],
    [
        'model' => $gpt4o, 'temp' => 0.7, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'B_gpt4o_t0.7_psychological',
        'diversity_instruction' => 'ให้เน้นการตีความในเชิงจิตวิทยา โดยเชื่อมโยงกับสภาวะอารมณ์ ความรู้สึก ความเครียด หรือความปรารถนาที่ซ่อนอยู่ของผู้ฝัน'
    ],
    [
        'model' => $gpt4o, 'temp' => 1.0, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'C_gpt4o_t1.0_creative',
        'diversity_instruction' => 'ให้ตีความอย่างสร้างสรรค์ เปรียบเทียบเป็นอุปมาอุปไมย และคาดการณ์ถึงเหตุการณ์ในอนาคตที่อาจเกิดขึ้นได้หลายแง่มุม'
    ],
    [
    'model' => $gpt5,  'temp' => 0.8, 'top_p' => 1.0, 'source' => 'gpt-5', 'arm_id' => 'E_gpt5_uncertainty',
    'diversity_instruction' => 'หากความฝันมีความหมายได้หลายแง่มุม ให้สร้างคำทำนายแบบมีเงื่อนไข (Scenarios) 2-3 รูปแบบตามสถานการณ์ชีวิตที่ผู้ฝันอาจเผชิญอยู่',
    'enable_uncertainty' => true // <-- เพิ่ม flag ใหม่
],
];


foreach ($experimentArms as $arm) {
  try {
    $prompt_for_ai = $dreamText;
    if ($retrieved_context !== '') $prompt_for_ai = $retrieved_context . "\n\n---\n\nความฝันของผู้ใช้:\n" . $dreamText;
    
    // Call AI with a specific diversity instruction for each arm
    $gen = $ai->responsesGenerate($arm['model'], $prompt_for_ai, (float)$arm['temp'], (float)$arm['top_p'], $arm['diversity_instruction'], $seen, null);
    $gen = Safety::postFilter($ai, $gen);

    $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>$arm['diversity_instruction'], 'content'=>$gen];
    $seen[] = (string)($gen['interpretation'] ?? '');
  } catch (\Throwable $e) {
    // On error, create a fallback option but still tag it with the intended arm for tracking
    $fb = Safety::fallback();
    $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>'error-fallback', 'content'=>$fb];
  }
}
// --- END MODIFICATION ---

if ($inputFlagged) { foreach ($options as &$opt) { $opt['content'] = Safety::fallback(); } unset($opt); }

$optionIds = [];
foreach ($options as $opt) {
  $id = insertOption($db, $reportId, $opt['source'], $opt['model'], $opt['t'], $opt['p'], $opt['content'], 'pending', $opt['angle'], $opt['style_key']);
  try {
    $outText = json_encode($opt['content'], JSON_UNESCAPED_UNICODE) ?: '';
    $omod = $ai->moderate($outText);
    logModeration($db, 'output', $id, 'omni-moderation-latest', $omod);
    $flag = (int)($omod['results'][0]['flagged'] ?? 0);
    $db->execStmt('UPDATE generated_options SET moderation_label=? WHERE id=?', [$flag?'flagged':'safe', $id]);
    if ($flag) $db->execStmt('UPDATE generated_options SET content_json=? WHERE id=?', [json_encode(Safety::fallback(), JSON_UNESCAPED_UNICODE), $id]);
  } catch (\Throwable $e) { $db->execStmt('UPDATE generated_options SET moderation_label=? WHERE id=?', ['safe', $id]); }
  $optionIds[] = $id;
}

$slots = ['A','B','C','D']; $slotOrder = $slots; shuffle($slotOrder);
$map = array_combine($slotOrder, $optionIds);
$sessionId = (int)$db->execStmt('INSERT INTO presentation_sessions(report_id, slot_order, A_option_id, B_option_id, C_option_id, D_option_id) VALUES (?,?,?,?,?,?)', [$reportId, json_encode($slotOrder), $map['A'],$map['B'],$map['C'],$map['D']]);

$slotContent = [];
foreach ($slots as $s) {
  $row = $db->one('SELECT content_json FROM generated_options WHERE id=?', [$map[$s]]);
  $slotContent[$s] = $row ? (json_decode($row['content_json'], true) ?: Safety::fallback()) : Safety::fallback();
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
  document.getElementById('loading-state').style.display = 'none';
  document.getElementById('results-container').style.display = 'block';
  document.title = 'เลือกคำทำนายที่คุณชอบที่สุด — DreamAI';
});
</script>
</body>
</html>
