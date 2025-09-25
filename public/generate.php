<?php
/**
 * public/generate.php
 * REFACTORED: Now a backend-only script.
 * - No longer outputs HTML.
 * - Performs all AI generation and database operations.
 * - Returns a JSON response with the session_id on success.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\OpenAI;
use DreamAI\Safety;
use DreamAI\Utils;

// --- JSON Header ---
header('Content-Type: application/json');

// --- Error Handling ---
function json_error(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// --- Start session and validation ---
session_start();
if (!isset($_SESSION['user_id'])) {
    json_error('Authentication required.', 401);
}

try {
    $db = new DB($config['db']);
    $ai = new OpenAI($config['openai']);
} catch (\Throwable $e) {
    json_error('Database or AI service configuration error.', 500);
}

$u = $db->one('SELECT consent_research, gender, birth_date FROM users WHERE id=?', [$_SESSION['user_id']]);
if (!$u || (int)$u['consent_research']!==1 || empty($u['gender']) || empty($u['birth_date'])) {
    json_error('User profile is incomplete.', 403);
}

// --- Helper Functions ---
function createReport(DB $db, int $userId, string $channel, string $rawText, string $mood='neutral'): int {
  return (int)$db->execStmt('INSERT INTO dream_reports(user_id, channel, raw_text, mood, language) VALUES (?,?,?,?,?)', [$userId, $channel, $rawText, $mood, 'th']);
}
function logModeration(DB $db, string $stage, int $entityId, string $model, array $result): void {
  $db->execStmt('INSERT INTO moderation_logs(stage, entity_id, model, result_json, flagged) VALUES (?,?,?,?,?)', [$stage, $entityId, $model, json_encode($result, JSON_UNESCAPED_UNICODE), (int)($result['results'][0]['flagged'] ?? 0)]);
}
function insertOption(DB $db, int $reportId, string $source, ?string $model, ?float $t, ?float $p, array $content, string $label='pending', ?string $angle=null, ?string $styleKey=null): int {
  $json = json_encode($content, JSON_UNESCAPED_UNICODE);
  return (int)$db->execStmt(
    'INSERT INTO generated_options (report_id, source, angle, style_key, diversity_method, model, temperature, top_p, prompt_version, content_json, risk_score, moderation_label) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    [$reportId, $source, $angle, $styleKey, 'fixed-arm', $model, $t, $p, 'v4-thesis-aligned', $json, 0.0, $label]
  );
}

// 1. Read inputs
$userId = (int)$_SESSION['user_id'];
$dreamText = Utils::normalizeText(trim($_POST['dream_text'] ?? ''), false);
$channel = 'text';
$reportId = createReport($db, $userId, $channel, '', 'neutral');

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
    json_error('Dream text cannot be empty.');
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

// --- Thesis-aligned Experiment Setup ---
$options = [];
$seen = [];
$gpt4o = $config['openai']['model_gpt4o'] ?? 'gpt-4o';
$gpt5  = $config['openai']['model_gpt5']  ?? 'gpt-5';

$experimentArms = [
    ['model' => $gpt4o, 'temp' => 0.3, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'A_gpt4o_t0.3_symbolic', 'diversity_instruction' => 'ให้เน้นการตีความตามสัญลักษณ์ที่ปรากฏในฝันและอ้างอิงตามตำราเป็นหลักอย่างตรงไปตรงมา'],
    ['model' => $gpt4o, 'temp' => 0.7, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'B_gpt4o_t0.7_psychological', 'diversity_instruction' => 'ให้เน้นการตีความในเชิงจิตวิทยา โดยเชื่อมโยงกับสภาวะอารมณ์ ความรู้สึก ความเครียด หรือความปรารถนาที่ซ่อนอยู่ของผู้ฝัน'],
    ['model' => $gpt4o, 'temp' => 1.0, 'top_p' => 1.0, 'source' => 'gpt-4.1', 'arm_id' => 'C_gpt4o_t1.0_creative', 'diversity_instruction' => 'ให้ตีความอย่างสร้างสรรค์ เปรียบเทียบเป็นอุปมาอุปไมย และคาดการณ์ถึงเหตุการณ์ในอนาคตที่อาจเกิดขึ้นได้หลายแง่มุม'],
    ['model' => $gpt5, 'temp' => 0.8, 'top_p' => 1.0, 'source' => 'gpt-5', 'arm_id' => 'E_gpt5_uncertainty', 'diversity_instruction' => 'หากความฝันมีความหมายได้หลายแง่มุม ให้สร้างคำทำนายแบบมีเงื่อนไข (Scenarios) 2-3 รูปแบบตามสถานการณ์ชีวิตที่ผู้ฝันอาจเผชิญอยู่', 'enable_uncertainty' => true],
];

foreach ($experimentArms as $arm) {
    try {
        $prompt_for_ai = $dreamText;
        if ($retrieved_context !== '') $prompt_for_ai = $retrieved_context . "\n\n---\n\nความฝันของผู้ใช้:\n" . $dreamText;
        $gen = $ai->responsesGenerate($arm['model'], $prompt_for_ai, (float)$arm['temp'], (float)$arm['top_p'], $arm['diversity_instruction'], $seen, null);
        $gen = Safety::postFilter($ai, $gen);
        $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>$arm['diversity_instruction'], 'content'=>$gen];
        $seen[] = (string)($gen['interpretation'] ?? '');
    } catch (\Throwable $e) {
        $fb = Safety::fallback();
        $options[] = ['source'=>$arm['source'], 'model'=>$arm['model'], 't'=>(float)$arm['temp'], 'p'=>(float)$arm['top_p'], 'angle'=>$arm['arm_id'], 'style_key'=>'error-fallback', 'content'=>$fb];
    }
}

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

// --- Final JSON Output ---
echo json_encode(['success' => true, 'sessionId' => $sessionId]);
