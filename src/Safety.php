<?php
/**
 * src/Safety.php
 * Content Safety & Cultural Sensitivity utilities for DreamAI (PHP 8+).
 *
 * MODIFIED: This version is softened for the research phase. It prioritizes
 * cleaning and rephrasing over aggressive blocking to allow more diverse,
 * nuanced outputs to be evaluated. The hard fallback is reserved for
 * truly high-risk content only.
 * ADDED: softenInputText() to preprocess user input before moderation.
 */

declare(strict_types=1);

namespace DreamAI;

final class Safety
{
    private const MAX_TITLE_LEN          = 80;
    private const MAX_INTERPRET_LEN      = 800;
    private const MAX_ADVICE_LEN         = 400;
    private const MAX_LUCKY_NUM_COUNT    = 6;

    // ✨ CHANGE: ลบฟังก์ชัน softenInputText เดิมออกไปทั้งหมด

    public static function hardBlocklist(): array
    {
        return [
            'การเมือง', 'ล้มล้าง', 'ปฏิวัติ', 'รัฐประหาร', 'สถาบัน',
            'ดูหมิ่นศาสนา', 'หมิ่นศาสนา',
            'เหยียดเชื้อชาติ', 'เหยียดผิว', 'เหยียดเพศ',
            'ทำร้ายตัวเอง', 'ทำร้ายผู้อื่น',
        ];
    }
    

    /** Regex patterns for PII to scrub */
    private static function piiPatterns(): array
    {
        return [
            '/\\b\\+?\\d[\\d\\s\\-]{6,}\\d\\b/u', // phone-like
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/iu', // email
            '/https?:\\/\\/[\\w.-]+(?:\\/[\\w\\-._~:?#\\[\\]@!$&\'()*+,;=\\/]*)?/iu', // URL
        ];
    }

    /** Safe, supportive fallback message */
    public static function fallback(): array
    {
        return [
            'title'          => 'คำแนะนำทั่วไป',
            'interpretation' => 'ความฝันนี้อาจสะท้อนความคิด ความกังวล หรือเหตุการณ์ในแต่ละวันของคุณ โดยไม่จำเป็นต้องเป็นลางบอกเหตุ',
            'tone'           => 'กลาง',
            'advice'         => 'พักผ่อนให้เพียงพอ ทำความดี มีสติ ไม่ประมาท และไตร่ตรองก่อนตัดสินใจเรื่องสำคัญ',
            'lucky_numbers'  => [],
            'culture'        => ['thai_buddhist_fit' => true, 'notes' => 'ข้อความกลางที่ปลอดภัย (Fallback)'],
        ];
    }

    // --- Main entry ---
    public static function postFilter(OpenAI $ai, array $option): array
    {
        // 1. Basic sanitation and PII scrubbing
        $opt = self::sanitizeOption($option);

        // 2. Check against HARD blocklist for high-risk content
        $joinedText = self::joinText($opt);
        foreach (self::hardBlocklist() as $kw) {
            if (mb_stripos($joinedText, $kw) !== false) {
                return self::fallback(); // Immediate fallback for severe violations
            }
        }

        // 3. Instead of blocking "fearful" phrases, we now soften them.
        $opt = self::softenFearfulPhrases($opt);

        // 4. Final length clamps & clean-up
        $opt['title']          = self::limitChars($opt['title'] ?? '', self::MAX_TITLE_LEN);
        $opt['interpretation'] = self::softClampParagraph($opt['interpretation'] ?? '', self::MAX_INTERPRET_LEN);
        $opt['advice']         = self::softClampParagraph($opt['advice'] ?? '', self::MAX_ADVICE_LEN);
        
        // 5. Tone enforcement
        $allowedTones = ['ปลอบโยน', 'เตือน', 'กลาง'];
        if (!isset($opt['tone']) || !in_array($opt['tone'], $allowedTones, true)) {
            $opt['tone'] = 'กลาง';
        }

        return $opt;
    }

    // --- Helpers ---
    public static function sanitizeOption(array $opt): array
    {
        $out = [
            'title'          => self::asString($opt['title'] ?? ''),
            'interpretation' => self::asString($opt['interpretation'] ?? ''),
            'tone'           => self::asString($opt['tone'] ?? 'กลาง'),
            'advice'         => self::asString($opt['advice'] ?? ''),
            'lucky_numbers'  => [],
            'culture'        => [
                'thai_buddhist_fit' => (bool)($opt['culture']['thai_buddhist_fit'] ?? true),
                'notes'             => self::asString($opt['culture']['notes'] ?? ''),
            ],
        ];

        if (isset($opt['lucky_numbers']) && is_array($opt['lucky_numbers'])) {
            $ln = [];
            foreach ($opt['lucky_numbers'] as $n) {
                if (is_numeric($n)) $ln[] = (int)$n;
                if (count($ln) >= self::MAX_LUCKY_NUM_COUNT) break;
            }
            $out['lucky_numbers'] = $ln;
        }

        return self::scrubPII($out);
    }

    private static function joinText(array $opt): string
    {
        $parts = [
            (string)($opt['title'] ?? ''),
            (string)($opt['interpretation'] ?? ''),
            (string)($opt['advice'] ?? ''),
            (string)($opt['culture']['notes'] ?? ''),
        ];
        return trim(implode(' | ', array_filter($parts, static fn($s) => $s !== '')));
    }
    
    private static function scrubPII(array $opt): array
    {
        $scrub = static function (string $s): string {
            foreach (self::piiPatterns() as $re) {
                $s = preg_replace($re, '', $s) ?? $s;
            }
            return trim(preg_replace('/\\s{2,}/u', ' ', $s) ?? $s);
        };

        foreach (['title','interpretation','advice'] as $k) {
            if (isset($opt[$k]) && is_string($opt[$k])) {
                $opt[$k] = $scrub($opt[$k]);
            }
        }
        if (isset($opt['culture']['notes']) && is_string($opt['culture']['notes'])) {
            $opt['culture']['notes'] = $scrub($opt['culture']['notes']);
        }
        return $opt;
    }

    private static function softenFearfulPhrases(array $opt): array
    {
        $replacements = [
            // Deterministic doom -> cautious suggestion
            '/จะ\\s*เกิด\\s*เรื่องร้ายแรง/u'       => 'อาจมีเรื่องท้าทายเข้ามา ควรใช้สติและวางแผนให้ดี',
            '/ตายแน่นอน|เสียชีวิตแน่นอน/u'        => 'ควรระมัดระวังและดูแลสุขภาพกายใจเป็นพิเศษ',
            '/ต้อง\\s*ประสบ\\s*อุบัติเหตุ/u'        => 'ควรเพิ่มความระมัดระวังในการเดินทางและการตัดสินใจที่เสี่ยง',
            '/เคราะห์ร้าย\\s*กำลังจะมา/u'         => 'อาจมีเรื่องไม่คาดคิดเกิดขึ้น ควรเตรียมรับมืออย่างใจเย็น',
            '/ศัตรู|คู่อาฆาต/u'                   => 'บุคคลที่ไม่หวังดี',
        ];

        foreach (['interpretation', 'advice'] as $field) {
            if (!empty($opt[$field]) && is_string($opt[$field])) {
                $opt[$field] = preg_replace(array_keys($replacements), array_values($replacements), $opt[$field]) ?? $opt[$field];
            }
        }
        return $opt;
    }

    private static function limitChars(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (mb_strlen($s, 'UTF-8') <= $max) return $s;
        return rtrim(mb_substr($s, 0, $max, 'UTF-8')) . '…';
    }

    private static function softClampParagraph(string $s, int $max): string
    {
        $s = trim($s);
        if ($s === '' || mb_strlen($s, 'UTF-8') <= $max) return $s;
        $cut = mb_substr($s, 0, $max, 'UTF-8');
        $pos = max(mb_strrpos($cut, '।'), mb_strrpos($cut, '.'), mb_strrpos($cut, '!', 0, 'UTF-8'), mb_strrpos($cut, '?', 0, 'UTF-8'));
        if ($pos !== false) {
            return rtrim(mb_substr($cut, 0, $pos + 1, 'UTF-8'));
        }
        return rtrim($cut) . '…';
    }

    private static function asString(mixed $v): string
    {
        if (is_string($v)) return $v;
        if ($v === null) return '';
        if (is_scalar($v)) return (string)$v;
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
