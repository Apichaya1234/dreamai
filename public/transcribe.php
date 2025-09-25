<?php
/**
 * public/transcribe.php
 * รับไฟล์เสียงจากเบราว์เซอร์ -> ส่งไป OpenAI Transcribe -> คืนข้อความ (JSON)
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\OpenAI;

header('Content-Type: application/json; charset=utf-8');

try {
    $ai = new OpenAI($config['openai']);

    $tmpPath = null;
    $cleanup = false;

    if (!empty($_FILES['audio']['tmp_name']) && is_uploaded_file($_FILES['audio']['tmp_name'])) {
        // ได้เป็นไฟล์อัปโหลดตรง ๆ
        $tmpPath = $_FILES['audio']['tmp_name'];
    } elseif (!empty($_POST['audio_b64'])) {
        // เผื่อรองรับ base64 (ถ้าคุณอยากใช้แบบเดิม)
        $bin = base64_decode((string)$_POST['audio_b64'], true);
        if ($bin === false) {
            throw new RuntimeException('Invalid base64 audio');
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'dreamai_');
        file_put_contents($tmpPath, $bin);
        $cleanup = true;
    } else {
        throw new RuntimeException('No audio provided');
    }

    $model = $config['openai']['transcribe'] ?? 'gpt-4o-transcribe';
    $res   = $ai->transcribe($tmpPath, $model);
    $text  = (string)($res['text'] ?? '');

    if ($cleanup && $tmpPath) { @unlink($tmpPath); }

    echo json_encode(['ok' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
