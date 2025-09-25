<?php
/**
 * src/OpenAI.php
 * Minimal OpenAI API client for DreamAI (PHP 8+), no external dependencies.
 *
 * Endpoints used:
 * - POST /v1/chat/completions         (rephrasing, generation, and classification tasks)
 * - POST /v1/moderations              (text-moderation-latest)
 * - POST /v1/audio/transcriptions     (whisper-1; multipart/form-data)
 *
 * Notes:
 * - This client focuses on the exact features needed by DreamAI.
 * - Throws RuntimeException on HTTP or JSON errors.
 * - MAJOR FIX: All generation calls now use the standard /v1/chat/completions endpoint
 * instead of the non-standard /v1/responses endpoint.
 */

declare(strict_types=1);

namespace DreamAI;

final class OpenAI {
    private string $apiKey;
    private ?string $org;
    private int $timeout;
    private int $connectTimeout;
    private ?string $baseUrl;

    public function __construct(array $cfg)
    {
        $this->apiKey         = (string)($cfg['api_key'] ?? '');
        $this->org            = $cfg['org'] ?? null;
        $this->timeout        = (int)($cfg['timeout'] ?? 60);
        $this->connectTimeout = (int)($cfg['connect_timeout'] ?? 10);
        $this->baseUrl        = isset($cfg['base_url']) && $cfg['base_url'] !== '' ? rtrim($cfg['base_url'], '/') : 'https://api.openai.com';
        if ($this->apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is missing.');
        }
    }

    public function rephraseForSafety(string $originalText, string $model = 'gpt-4o'): string
    {
        $systemPrompt = <<<TXT
You are an AI assistant that rephrases Thai sentences to be less explicit and violent, making them suitable for a general audience and content moderation systems, while preserving the original core meaning of a dream. Respond only with the rephrased sentence, without any additional explanation.
Example 1:
Input: ฝันว่าต่อสู้กับผีจนเลือดสาด
Output: ฝันว่าเผชิญหน้ากับวิญญาณและเห็นของเหลวสีแดงฉาน
Example 2:
Input: ฝันว่าถูกยิงตาย
Output: ฝันว่าชีวิตได้สิ้นสุดลงด้วยวัตถุคล้ายอาวุธ
TXT;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $originalText]
            ],
            'temperature' => 0.2,
            'max_tokens' => 250,
            'top_p' => 1,
        ];

        $json = $this->requestJson('POST', '/v1/chat/completions', $payload);
        $rephrasedText = $json['choices'][0]['message']['content'] ?? null;

        return (is_string($rephrasedText) && trim($rephrasedText) !== '') ? trim($rephrasedText) : $originalText;
    }

    public function responsesGenerate(
        string $model,
        string $userText,
        float $temperature,
        float $top_p,
        ?string $style = null,
        array $avoid = [],
        ?string $angle = null,
        bool $enable_uncertainty = false
    ): array {
        $jsonSchemaString = <<<JSON
{
    "angle": "string (one of: symbol, psycho, event, caution)",
    "title": "string (a concise, creative title for the dream interpretation in Thai)",
    "interpretation": "string OR array of objects. If array, each object must have 'condition' (string) and 'interpretation' (string) keys.",
    "tone": "string (one of: ปลอบโยน, เตือน, กลาง)",
    "advice": "string (actionable advice in Thai)",
    "lucky_numbers": "array of integers",
    "culture": {
        "thai_buddhist_fit": "boolean",
        "notes": "string (internal notes for analysis)"
    }
}
JSON;

        $systemPrompt = <<<TXT
คุณคือ "ปราชญ์แห่งความฝัน" ผู้มีสติปัญญาลุ่มลึกผสมผสานระหว่างโหราศาสตร์ไทยโบราณและหลักจิตวิเคราะห์สมัยใหม่
ภารกิจของคุณคือการตีความความฝันของผู้ใช้ โดยมีหลักการดังนี้:
1.  **เชื่อมโยงกับชีวิตจริง**: ทุกคำทำนายต้องพยายามเชื่อมโยงสัญลักษณ์ในฝันเข้ากับสถานการณ์จริงที่ผู้ใช้อาจเผชิญอยู่
2.  **อ้างอิงสัญลักษณ์**: ต้องหยิบยก "สัญลักษณ์ที่เด่นชัด" จากความฝันอย่างน้อย 2 อย่างมากล่าวถึงในคำทำนาย
3.  **ให้คำแนะนำที่จับต้องได้**: "advice" ต้องเป็นคำแนะนำที่นำไปปฏิบัติได้จริง
4.  **หลีกเลี่ยงการตัดสิน**: ห้ามทำนายเรื่องความเป็นความตายหรืออุบัติเหตุอย่างฟันธง
5.  **สร้างความแตกต่าง**: สร้างคำทำนายแต่ละครั้งให้แตกต่างกันอย่างชัดเจน อย่าใช้รูปประโยคหรือใจความซ้ำเดิม
ข้อห้าม: ห้ามใช้ประโยคสำเร็จรูปเช่น "ความฝันนี้อาจสะท้อนความคิด..."

Your response MUST be a valid JSON object that strictly conforms to the following schema. Do not include any text outside of the JSON object.
Schema:
$jsonSchemaString
TXT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        if ($style && trim($style) !== '') {
            $messages[] = ['role' => 'system', 'content' => 'สไตล์เพิ่มเติมที่ต้องใช้: ' . $style];
        }
        if (!empty($avoid)) {
            $avoidShort = [];
            foreach ($avoid as $s) {
                if (trim((string)$s) !== '') $avoidShort[] = mb_substr((string)$s, 0, 180, 'UTF-8');
            }
            if ($avoidShort) {
                $messages[] = ['role' => 'system', 'content' => "AVOID_LIST (ห้ามซ้ำสาระ/ถ้อยคำกับสิ่งเหล่านี้):\n- " . implode("\n- ", $avoidShort)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'top_p' => $top_p,
            'max_tokens' => 2048,
            'response_format' => ['type' => 'json_object']
        ];

        $json = $this->requestJson('POST', '/v1/chat/completions', $payload);
        $textContent = $json['choices'][0]['message']['content'] ?? '{}';
        $data = json_decode($textContent, true);

        return is_array($data) ? $data : Safety::fallback();
    }
    
    public function moderate(string $text): array
    {
        return $this->requestJson('POST', '/v1/moderations', ['model' => 'text-moderation-latest', 'input' => $text]);
    }

    public function transcribe(string $filepath, string $model = 'whisper-1'): array
    {
        if (!is_file($filepath)) throw new \RuntimeException("File not found for transcription: {$filepath}");
        $url = $this->baseUrl . '/v1/audio/transcriptions';
        $ch  = curl_init($url);
        $headers = ['Authorization: Bearer ' . $this->apiKey];
        if ($this->org) $headers[] = 'OpenAI-Organization: ' . $this->org;
        $post = ['model' => $model, 'file'  => new \CURLFile($filepath)];
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $post, CURLOPT_CONNECTTIMEOUT => $this->connectTimeout, CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $res = curl_exec($ch);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException('cURL error (transcribe): ' . $err); }
        $info = curl_getinfo($ch);
        curl_close($ch);
        $status = (int)($info['http_code'] ?? 0);
        $json = json_decode($res, true);
        if ($status >= 400 || !is_array($json)) {
            $msg = $this->extractApiError($json ?? []) ?? 'HTTP ' . $status;
            throw new \RuntimeException('OpenAI transcribe error: ' . $msg);
        }
        return $json;
    }

    private function requestJson(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        if ($this->org) $headers[] = 'OpenAI-Organization: ' . $this->org;
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout, CURLOPT_TIMEOUT => $this->timeout,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException('cURL error: ' . $err); }
        $info = curl_getinfo($ch);
        curl_close($ch);
        $status = (int)($info['http_code'] ?? 0);
        $json   = json_decode($res, true);
        if ($status >= 400 || !is_array($json)) {
            $msg = $this->extractApiError($json ?? []) ?? 'HTTP ' . $status;
            throw new \RuntimeException('OpenAI API Error: ' . $msg);
        }
        return $json;
    }

    private function extractApiError(array $json): ?string
    {
        if (isset($json['error']['message'])) {
            return (string)$json['error']['message'];
        }
        return null;
    }
}

?>