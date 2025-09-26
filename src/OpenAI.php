<?php
/**
 * src/OpenAI.php
 * Minimal OpenAI API client for DreamAI (PHP 8+), no external dependencies.
 *
 * Endpoints used:
 * - POST /v1/responses                  (text generation; Structured Outputs)
 * - POST /v1/moderations                (omni-moderation-latest)
 * - POST /v1/audio/transcriptions       (gpt-4o-transcribe; multipart/form-data)
 * - POST /v1/embeddings                 (text-embedding-3-*)
 *
 * Notes:
 * - This client focuses on the exact features needed by DreamAI.
 * - Throws RuntimeException on HTTP or JSON errors.
 * - Response parsing for /responses is defensive (handles multiple shapes).
 */

declare(strict_types=1);

namespace DreamAI;

final class OpenAI
{
    private string $apiKey;
    private ?string $org;
    private int $timeout;
    private int $connectTimeout;
    private ?string $baseUrl;

    /**
     * @param array{
     * api_key:string,
     * org?:?string,
     * model_gpt4o?:string,
     * model_gpt5?:string,
     * embedding?:string,
     * transcribe?:string,
     * timeout?:int,
     * connect_timeout?:int,
     * base_url?:string
     * } $cfg
     */
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

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /**
     * Generate one structured option (DreamOption schema) from user text.
     * Returns an associative array (already json-decoded) that matches the schema.
     */
    // ======================================================================
    // Replace this whole method in src/OpenAI.php
    // ======================================================================
    public function responsesGenerate(
        string $model,
        string $userText,
        float $temperature,
        float $top_p,
        ?string $style = null,
        array $avoid = [],
        ?string $angle = null
    ): array {
        $SYSTEM_PROMPT = <<<TXT
คุณคือ "ปราชญ์แห่งความฝัน" ผู้มีสติปัญญาลุ่มลึกผสมผสานระหว่างโหราศาสตร์ไทยโบราณและหลักจิตวิเคราะห์สมัยใหม่ ภารกิจของคุณคือการตีความความฝันของผู้ใช้ โดยมีหลักการดังนี้:

บทบาท:
- เป็นผู้ให้คำปรึกษาที่สุขุม สุภาพ และน่าเชื่อถือ
- ใช้ภาษาไทยที่สละสลวย แต่เข้าใจง่าย

หลักการตีความ:
1.  **ตีความเชิงสัญลักษณ์:** หากฝันถึงเรื่องที่ดูรุนแรงหรือน่ากลัว (เช่น การต่อสู้, ผี, เลือด, ความตาย) ให้ตีความในเชิงสัญลักษณ์เสมอ อย่าทำนายตามตัวอักษร ให้มองว่าสิ่งเหล่านั้นเป็นตัวแทนของ "ความเครียด", "ความท้าทาย", "การเปลี่ยนแปลง" หรือ "อุปสรรค" ในชีวิตของผู้ฝัน
2.  **เชื่อมโยงกับชีวิตจริง**: ทุกคำทำนายต้องพยายามเชื่อมโยงสัญลักษณ์ในฝันเข้ากับสถานการณ์จริงที่ผู้ใช้อาจเผชิญอยู่ เช่น การงาน การเงิน ความสัมพันธ์ หรือความกังวลส่วนตัว
3.  **อ้างอิงสัญลักษณ์**: ต้องหยิบยก "สัญลักษณ์ที่เด่นชัด" จากความฝันอย่างน้อย 1-2 อย่างมากล่าวถึงในคำทำนาย เพื่อให้ผู้ใช้รู้สึกว่าคำทำนายนี้สร้างมาเพื่อเขาโดยเฉพาะ
4.  **ให้คำแนะนำที่จับต้องได้**: "advice" ต้องเป็นคำแนะนำที่นำไปปฏิบัติได้จริง ไม่ใช่แค่คำพูดสวยหรู
5.  **สร้างความหลากหลาย**: พยายามสร้างคำทำนายและใช้ถ้อยคำที่ไม่ซ้ำกับคำทำนายก่อนหน้าที่เคยให้ไปแล้ว (AVOID_LIST)

ข้อห้ามเด็ดขาด:
- ห้ามทำนายเรื่องความเป็นความตายหรืออุบัติเหตุอย่างฟันธง ให้ใช้การเตือนสติด้วยความระมัดระวังแทน เช่น "ควรเพิ่มความระมัดระวังในการเดินทาง"
- หลีกเลี่ยงหัวข้อที่อ่อนไหว (การเมือง, ศาสนาที่สร้างความขัดแย้ง, การเหยียดเชื้อชาติ) อย่างเคร่งครัด
TXT;

        // messages → ใช้ content.type = input_text ตามสเปค Responses API
        $msgs = [];
        $push = function(string $role, string $text) use (&$msgs) {
            $msgs[] = [
                'role'    => $role,
                'content' => [ [ 'type' => 'input_text', 'text' => $text ] ],
            ];
        };
        $push('system', $SYSTEM_PROMPT);
        if ($angle) $push('system', "ให้ใช้มุมมอง angle='{$angle}' เท่านั้น");
        if ($style && trim($style) !== '') $push('system', 'สไตล์เพิ่มเติม: '.$style);
        if (!empty($avoid)) {
            $avoidShort = [];
            foreach ($avoid as $s) {
                $s = trim(preg_replace('/\s+/', ' ', (string)$s));
                if ($s !== '') $avoidShort[] = mb_substr($s, 0, 180, 'UTF-8');
            }
            if ($avoidShort) $push('system', "AVOID_LIST (ห้ามซ้ำสาระ/ถ้อยคำกับ):\n- ".implode("\n- ", $avoidShort));
        }
        $push('user', $userText);

        // text.format → JSON Schema แบบ strict (required ครบทุกคีย์ + additionalProperties:false ทุกชั้น)
        $payload = [
            'model'       => $model,
            'input'       => $msgs,
            'temperature' => $temperature,
            'top_p'       => $top_p,
            'text' => [
                'format' => [
                    'type'   => 'json_schema',
                    'name'   => 'DreamOption',
                    'strict' => true,
                    'schema' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'angle'          => ['type'=>'string','enum'=>['symbol','psycho','event','caution']],
                            'title'          => ['type'=>'string'],
                            'interpretation' => ['type'=>'string'],
                            'tone'           => ['type'=>'string','enum'=>['ปลอบโยน','เตือน','กลาง']],
                            'advice'         => ['type'=>'string'],
                            'lucky_numbers'  => ['type'=>'array','items'=>['type'=>'integer']],
                            'culture'        => [
                                'type'                 => 'object',
                                'additionalProperties' => false,
                                'properties'           => [
                                    'thai_buddhist_fit' => ['type'=>'boolean'],
                                    'notes'             => ['type'=>'string'],
                                ],
                                // ⬇️ strict: ต้องใส่ครบทุกคีย์ใน properties
                                'required'            => ['thai_buddhist_fit','notes'],
                            ],
                        ],
                        // ⬇️ strict: ใส่ครบทุกคีย์ระดับบนสุด
                        'required' => ['angle','title','interpretation','tone','advice','lucky_numbers','culture'],
                    ],
                ],
            ],
        ];
        
        // ส่วนนี้ไว้สำหรับ Arm 'E_gpt5_uncertainty'
        if (!empty($arm['enable_uncertainty'])) {
            $payload['text']['format']['schema']['properties']['interpretation'] = [
                'type' => 'array',
                'description' => 'กรณีฝันกำกวม ให้ตีความหลายแง่มุมตามเงื่อนไขที่เป็นไปได้ 2-3 กรณี',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'condition' => ['type' => 'string', 'description' => 'เงื่อนไขของสถานการณ์ เช่น "หากช่วงนี้คุณกังวลเรื่องงาน..."'],
                        'interpretation' => ['type' => 'string', 'description' => 'คำทำนายภายใต้เงื่อนไขนั้น'],
                    ],
                    'required' => ['condition', 'interpretation'],
                ],
            ];
        }


        $json = $this->requestJson('POST', '/v1/responses', $payload);
        $text = $this->extractResponsesText($json);

        $data = json_decode($text, true);
        if (!is_array($data)) {
            // หาก AI ตอบกลับมาเป็น JSON ที่ไม่ถูกต้อง จะใช้ Fallback นี้แทน
            return Safety::fallback();
        }
        if ($angle && (empty($data['angle']) || $data['angle'] !== $angle)) $data['angle'] = $angle;
        return $data;
    }

    /**
     * Call Moderations endpoint (omni-moderation-latest).
     * Returns raw moderation payload (assoc array).
     */
    public function moderate(string $text): array
    {
        return $this->requestJson('POST', '/v1/moderations', [
            'model' => 'omni-moderation-latest',
            'input' => $text,
        ]);
    }

    /**
     * Transcribe an audio file using gpt-4o-transcribe (multipart/form-data).
     * Returns the raw JSON (assoc array). Typical shape contains 'text'.
     */
    public function transcribe(string $filepath, string $model = 'gpt-4o-transcribe'): array
    {
        if (!is_file($filepath)) {
            throw new \RuntimeException("File not found for transcription: {$filepath}");
        }

        $url = $this->baseUrl . '/v1/audio/transcriptions';
        $ch  = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];
        if ($this->org) {
            $headers[] = 'OpenAI-Organization: ' . $this->org;
        }

        $post = [
            'model' => $model,
            'file'  => new \CURLFile($filepath),
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_POSTFIELDS      => $post,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_TIMEOUT         => $this->timeout,
        ]);

        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error (transcribe): ' . $err);
        }
        curl_close($ch);

        $status = (int)($info['http_code'] ?? 0);
        $json   = json_decode($res, true);
        if (!is_array($json)) {
            $snippet = mb_substr($res, 0, 500);
            throw new \RuntimeException("OpenAI transcribe: Non-JSON response (HTTP {$status}): {$snippet}");
        }
        if ($status >= 400) {
            $msg = $this->extractApiError($json) ?? 'HTTP ' . $status;
            throw new \RuntimeException('OpenAI transcribe error: ' . $msg);
        }
        return $json;
    }

    /**
     * Create embeddings for a given string.
     * Returns raw embeddings JSON (assoc array).
     * Typical usage: $json['data'][0]['embedding'] (vector of floats).
     */
    public function embed(string $text, string $model = 'text-embedding-3-small'): array
    {
        return $this->requestJson('POST', '/v1/embeddings', [
            'model' => $model,
            'input' => $text,
        ]);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Perform a JSON request (POST by default).
     *
     * @param 'POST'|'GET' $method
     * @param string       $path
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function requestJson(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
        if ($this->org) {
            $headers[] = 'OpenAI-Organization: ' . $this->org;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
        ];

        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . $err);
        }
        curl_close($ch);

        $status = (int)($info['http_code'] ?? 0);
        $json   = json_decode($res, true);

        if (!is_array($json)) {
            $snippet = mb_substr($res, 0, 500);
            throw new \RuntimeException("OpenAI: Non-JSON response (HTTP {$status}): {$snippet}");
        }
        if ($status >= 400) {
            $msg = $this->extractApiError($json) ?? 'HTTP ' . $status;
            throw new \RuntimeException('OpenAI error: ' . $msg);
        }

        return $json;
    }

    /**
     * Extract human-readable error message from OpenAI error JSON.
     * Accepts shapes like: {"error":{"message":"...","type":"..."}}
     */
    private function extractApiError(array $json): ?string
    {
        if (isset($json['error'])) {
            if (is_array($json['error'])) {
                $parts = [];
                if (isset($json['error']['message'])) $parts[] = (string)$json['error']['message'];
                if (isset($json['error']['type']))    $parts[] = '(' . (string)$json['error']['type'] . ')';
                if ($parts) return implode(' ', $parts);
            } elseif (is_string($json['error'])) {
                return $json['error'];
            }
        }
        return null;
    }

    /**
     * Pull a text output from the diverse /v1/responses shapes.
     * Tries common fields in priority order.
     */
    private function extractResponsesText(array $json): string
    {
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }
        if (isset($json['output']) && is_array($json['output'])) {
            foreach ($json['output'] as $blk) {
                if (($blk['type'] ?? '') === 'message' && !empty($blk['content'])) {
                    foreach ($blk['content'] as $c) {
                        if (isset($c['text']) && is_string($c['text'])) return $c['text'];
                    }
                }
            }
        }
        if (isset($json['message']['content'][0]['text'])) {
            return (string)$json['message']['content'][0]['text'];
        }
        return json_encode($json, JSON_UNESCAPED_UNICODE);
    }
	
	public function classifyMood(string $text, ?string $model = null): string
    {
        $model = $model ?? (getenv('MODEL_GPT4O') ?: 'gpt-4o');
        
        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' =>
                    'คุณเป็นตัวจำแนกอารมณ์ของความฝันในภาษาไทย ให้ผลลัพธ์เป็นหนึ่งค่า: positive, negative, หรือ neutral เท่านั้น ' .
                    'พิจารณาบริบทคำ เช่น กลัว ตื่นตระหนก โศกเศร้า = negative, อุ่นใจ โล่งใจ ดีใจ = positive, อื่น ๆ หรือกำกวม = neutral'
                ],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0,
            'top_p'       => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => 'DreamMood',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'mood' => ['type' => 'string', 'enum' => ['positive','negative','neutral']]
                        ],
                        'required' => ['mood']
                    ],
                ],
            ],
        ];

        $json = $this->requestJson('POST', '/v1/responses', $payload);
        $textOut = $this->extractResponsesText($json);
        $data = json_decode($textOut, true);
        $mood = is_array($data) ? ($data['mood'] ?? null) : null;

        return in_array($mood, ['positive','negative','neutral'], true) ? $mood : 'neutral';
    }
}
