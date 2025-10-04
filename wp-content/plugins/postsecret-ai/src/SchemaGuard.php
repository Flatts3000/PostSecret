<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Normalizes the model payload to your final schema:
 * - fills required keys with defaults
 * - clamps/rounds 0..1 numbers to 2 decimals
 * - enforces enums (falls back to "unknown")
 * - trims/normalizes strings
 * - de-dupes/sorts arrays (tags, labels, piiTypes)
 * - ensures per-side objects exist (or coerces null -> default block)
 */
final class SchemaGuard
{
    /** Default object for media.defects.overall */
    private const DEF_OVERALL = [
        'sharpness' => 'unknown',
        'exposure' => 'unknown',
        'colorCast' => 'unknown',
        'severity' => 'unknown',
        'notes' => '',
    ];

    /** Default defect entry */
    private const DEF_DEFECT = [
        'code' => 'other',
        'severity' => 'low',
        'coverage' => 0.00,
        'confidence' => 0.00,
        'region' => ['x' => 0.00, 'y' => 0.00, 'w' => 0.00, 'h' => 0.00],
        'where' => 'unknown',
    ];

    /** Default side block */
    private const DEF_SIDE = [
        'artDescription' => '',
        'fontDescription' => ['style' => 'unknown', 'notes' => ''],
        'text' => ['fullText' => null, 'language' => 'unknown'],
    ];

    /** Full defaults shape */
    private const DEF_PAYLOAD = [
        'topics' => [],
        'feelings' => [],
        'meanings' => [],
        'vibe' => [],
        'style' => 'unknown',
        'locations' => [],
        'wisdom' => '',
        'secretDescription' => '',
        'media' => [
            'type' => 'unknown',
        ],
        'front' => self::DEF_SIDE,
        'back' => self::DEF_SIDE,
        'moderation' => [
            'reviewStatus' => 'auto_vetted',
            'labels' => [],
            'nsfwScore' => 0.00,
            'containsPII' => false,
            'piiTypes' => [],
        ],
        'confidence' => [
            'overall' => 0.00,
            'byField' => [
                'facets' => 0.00,
                'artDescription' => 0.00,
                'fontDescription' => 0.00,
                'moderation' => 0.00,
            ],
        ],
    ];

    /** Allowed enums */
    private const ENUMS = [
        'media.type' => ['postcard', 'note_card', 'letter', 'photo', 'poster', 'mixed', 'unknown'],
        'vibe' => ['bittersweet', 'confessional', 'defiant', 'eerie', 'gentle', 'grim', 'hopeful', 'melancholic', 'nostalgic', 'ominous', 'playful', 'raw', 'serene', 'somber', 'tense', 'tender', 'wistful'],
        'style' => ['art_deco', 'abstract', 'minimalism', 'collage', 'pop_art', 'surrealism', 'expressionism', 'bauhaus', 'constructivist', 'grunge', 'vaporwave', 'doodle', 'cutout', 'watercolor', 'oil_painting', 'pencil_sketch', 'photomontage', 'glitch', 'pixel_art', 'graffiti', 'calligraphic', 'stencil', 'typographic', 'realist_photo', 'mixed_media', 'unknown'],
        'font.style' => ['handwritten', 'typed', 'stenciled', 'mixed', 'unknown'],
        'reviewStatus' => ['auto_vetted', 'needs_review', 'reject_candidate'],
        'moderation.labels' => [
            // Policy-routing labels
            'self_harm_mention', 'self_harm_instructions', 'threat', 'extremism_promotion',
            'hate_violence', 'sexual_violence', 'sexual_content', 'minors_context', 'ncii',
            'fraud_malware', 'illicit_instructions', 'targeted_harassment', 'pii_present_strong', 'slur_present',
            // Reader-facing warning labels
            'suicide_mention', 'violence', 'abuse', 'child_abuse', 'death_grief', 'eating_disorder',
            'substance_use', 'pregnancy_loss', 'abortion', 'crime_illegal_activity',
            'stalking_harassment', 'weapons', 'blood_gore',
        ],
        'lang' => null, // any ISO 639-1 or "unknown"; we just lowercase
        'piiTypes' => ['name', 'email', 'phone', 'address', 'other'],
    ];

    /** Public entry */
    public static function normalize($in): array
    {
        $p = is_array($in) ? $in : [];

        $out = self::DEF_PAYLOAD;

        // facets (text-only)
        $out['topics'] = self::norm_list($p['topics'] ?? [], maxLen: 4);
        $out['feelings'] = self::norm_list($p['feelings'] ?? [], maxLen: 3);
        $out['meanings'] = self::norm_list($p['meanings'] ?? [], maxLen: 2);

        // facets (image+text)
        $out['vibe'] = self::norm_list($p['vibe'] ?? [], maxLen: 2, allowed: self::ENUMS['vibe']);
        $out['style'] = self::enum($p['style'] ?? 'unknown', 'style');
        $out['locations'] = self::norm_list($p['locations'] ?? [], maxLen: 5);

        // wisdom (text-only)
        $out['wisdom'] = self::truncate_words(self::norm_str($p['wisdom'] ?? ''), 25);

        // secretDescription
        $out['secretDescription'] = self::norm_str($p['secretDescription'] ?? '');

        // media
        $out['media']['type'] = self::enum($p['media']['type'] ?? 'unknown', 'media.type');

        // front & back blocks
        $out['front'] = self::norm_side($p['front'] ?? null);
        // Allow null back per prompt; coerce null -> default block for storage
        $out['back'] = is_null($p['back'] ?? null) ? self::DEF_SIDE : self::norm_side($p['back']);

        // moderation
        $m = $p['moderation'] ?? [];
        $out['moderation'] = [
            'reviewStatus' => self::enum($m['reviewStatus'] ?? 'auto_vetted', 'reviewStatus'),
            'labels' => self::norm_list($m['labels'] ?? [], maxLen: 6, allowed: self::ENUMS['moderation.labels']),
            'nsfwScore' => self::f01($m['nsfwScore'] ?? 0.00),
            'containsPII' => (bool)($m['containsPII'] ?? false),
            'piiTypes' => self::norm_list($m['piiTypes'] ?? [], allowed: self::ENUMS['piiTypes']),
        ];

        // confidence
        $c = $p['confidence'] ?? [];
        $bf = $c['byField'] ?? [];
        $out['confidence'] = [
            'overall' => self::f01($c['overall'] ?? 0.00),
            'byField' => [
                'facets' => self::f01($bf['facets'] ?? 0.00),
                'artDescription' => self::f01($bf['artDescription'] ?? 0.00),
                'fontDescription' => self::f01($bf['fontDescription'] ?? 0.00),
                'moderation' => self::f01($bf['moderation'] ?? 0.00),
            ],
        ];

        return $out;
    }

    /** -------- helpers -------- */

    private static function norm_side($side): array
    {
        if (!is_array($side)) return self::DEF_SIDE;

        // artDescription
        $art = self::norm_str($side['artDescription'] ?? '');
        // fontDescription
        $fd = $side['fontDescription'] ?? [];
        $font = [
            'style' => self::enum($fd['style'] ?? 'unknown', 'font.style'),
            'notes' => self::norm_str($fd['notes'] ?? ''),
        ];
        // text
        $tx = $side['text'] ?? [];
        $full = array_key_exists('fullText', $tx) ? $tx['fullText'] : null;
        $full = is_string($full) ? self::norm_text($full, 2000) : null;
        $lang = strtolower(self::norm_str($tx['language'] ?? 'unknown'));
        if ($lang === '') $lang = 'unknown';

        return [
            'artDescription' => $art,
            'fontDescription' => $font,
            'text' => [
                'fullText' => $full,
                'language' => $lang,
            ],
        ];
    }

    private static function norm_defects(array $arr, int $limit): array
    {
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) continue;
            $d = self::DEF_DEFECT;
            $d['code'] = self::enum($row['code'] ?? 'other', 'defect.code');
            $d['severity'] = self::enum($row['severity'] ?? 'low', 'severity');
            $d['coverage'] = self::f01($row['coverage'] ?? 0.00);
            $d['confidence'] = self::f01($row['confidence'] ?? 0.00);
            $r = $row['region'] ?? [];
            $d['region'] = [
                'x' => self::f01($r['x'] ?? 0.00),
                'y' => self::f01($r['y'] ?? 0.00),
                'w' => self::f01($r['w'] ?? 0.00),
                'h' => self::f01($r['h'] ?? 0.00),
            ];
            $d['where'] = self::enum($row['where'] ?? 'unknown', 'where');
            $out[] = $d;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function enum($v, string $key): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        $allowed = self::ENUMS[$key] ?? null;
        if ($allowed === null) {
            // open set (language)
            return $v !== '' ? $v : 'unknown';
        }
        return in_array($v, $allowed, true) ? $v : 'unknown';
    }

    private static function f01(mixed $n): float
    {
        $x = is_numeric($n) ? (float)$n : 0.00;
        if ($x < 0.0) $x = 0.0;
        if ($x > 1.0) $x = 1.0;
        return round($x, 2);
    }

    private static function norm_str(mixed $s): string
    {
        if (!is_string($s)) return '';
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return is_string($s) ? $s : '';
    }

    private static function norm_text(string $s, int $maxChars): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace("/[ \t]+/u", ' ', $s);
        $s = trim($s);
        if (mb_strlen($s, 'UTF-8') > $maxChars) {
            $s = mb_substr($s, 0, $maxChars, 'UTF-8') . ' … [TRUNCATED]';
        }
        return $s;
    }

    /**
     * Normalize a list of strings: lowercase, trim, dedupe, sort, optionally filter to allowed set, limit length.
     * @param mixed $arr
     * @param int $maxLen
     * @param array|null $allowed
     * @return array
     */
    private static function norm_list(mixed $arr, int $maxLen = 50, ?array $allowed = null): array
    {
        if (!is_array($arr)) $arr = [];
        $norm = [];
        foreach ($arr as $t) {
            if (!is_string($t)) continue;
            $x = strtolower(trim($t));
            if ($x === '') continue;
            if ($allowed && !in_array($x, $allowed, true)) continue;
            $norm[$x] = true;
            if (count($norm) >= $maxLen) break;
        }
        $out = array_keys($norm);
        sort($out, SORT_STRING);
        return $out;
    }

    private static function truncate_chars(string $s, int $limit): string
    {
        if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
        return mb_substr($s, 0, $limit, 'UTF-8');
    }

    private static function truncate_words(string $s, int $maxWords): string
    {
        if ($s === '') return '';
        $words = preg_split('/\s+/u', $s);
        if (count($words) <= $maxWords) return $s;
        return implode(' ', array_slice($words, 0, $maxWords));
    }
}