<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

/**
 * Orientation + color extraction with graceful fallbacks.
 * - Orientation from image dimensions.
 * - Primary color + ≤5 palette using a light quantization pass.
 */
final class Metadata
{
    public static function compute_and_store(int $att_id): array
    {
        $path = get_attached_file($att_id);
        if (!$path || !file_exists($path)) return [];

        // --- Orientation ---
        $w = $h = 0;
        if (function_exists('getimagesize')) {
            $dims = @getimagesize($path);
            if (is_array($dims)) {
                $w = (int)($dims[0] ?? 0);
                $h = (int)($dims[1] ?? 0);
            }
        }
        $orientation = self::orientation_from_size($w, $h);

        // --- Palette (primary + top ≤5) ---
        [$primary, $palette] = self::palette_hexes($path, 5);

        // Store in post meta (WP-friendly for queries)
        update_post_meta($att_id, '_ps_orientation', $orientation);
        if ($primary) update_post_meta($att_id, '_ps_primary_hex', $primary);
        if (!empty($palette)) update_post_meta($att_id, '_ps_palette', array_values($palette));

        return ['orientation' => $orientation, 'primary_hex' => $primary, 'palette' => $palette];
    }

    private static function orientation_from_size(int $w, int $h): string
    {
        if ($w <= 0 || $h <= 0) return 'unknown';
        if ($w === $h) return 'square';
        return ($w > $h) ? 'landscape' : 'portrait';
    }

    /**
     * Returns [primary_hex, palette_hexes[]].
     * Tries Imagick (fast, accurate) then GD (portable). Falls back to white.
     * Filters palette to ensure minimum perceptual distance between colors.
     */
    private static function palette_hexes(string $path, int $k = 5): array
    {
        // Try Imagick histogram if available
        if (class_exists('\Imagick')) {
            try {
                $im = new \Imagick($path);
                // Downscale for speed
                $im->thumbnailImage(512, 512, true);
                $hist = $im->getImageHistogram(); // array of ImagickPixel
                $counts = [];
                foreach ($hist as $px) {
                    /** @var \ImagickPixel $px */
                    $rgb = $px->getColor(); // ['r'=>..,'g'=>..,'b'=>..]
                    $hex = self::rgb_hex($rgb['r'], $rgb['g'], $rgb['b']);
                    $counts[$hex] = ($counts[$hex] ?? 0) + 1;
                }
                arsort($counts);
                $hexes = array_keys($counts);
                $filtered = self::filter_similar_colors($hexes, $k);
                return [$filtered[0] ?? '#ffffff', $filtered];
            } catch (\Throwable $e) { /* fall through */
            }
        }

        // GD fallback: load, shrink, quantize into 4-bit buckets, count
        if (function_exists('imagecreatefromstring')) {
            $bytes = @file_get_contents($path);
            if ($bytes !== false) {
                $src = @imagecreatefromstring($bytes);
                if ($src !== false) {
                    $sw = imagesx($src);
                    $sh = imagesy($src);
                    $max = 256;
                    $scale = ($sw > $sh) ? ($max / max(1, $sw)) : ($max / max(1, $sh));
                    $tw = max(1, (int)round($sw * $scale));
                    $th = max(1, (int)round($sh * $scale));
                    $tmp = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($tmp, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

                    $counts = [];
                    // stride to keep it cheap
                    $sx = max(1, (int)floor($tw / 64));
                    $sy = max(1, (int)floor($th / 64));
                    for ($y = 0; $y < $th; $y += $sy) {
                        for ($x = 0; $x < $tw; $x += $sx) {
                            $idx = imagecolorat($tmp, $x, $y);
                            $r = ($idx >> 16) & 0xFF;
                            $g = ($idx >> 8) & 0xFF;
                            $b = $idx & 0xFF;
                            // 4-bit/channel quantization: 0..15
                            $rq = $r >> 4;
                            $gq = $g >> 4;
                            $bq = $b >> 4;
                            // center back to 0..255
                            $rc = ($rq << 4) | 0x8;
                            $gc = ($gq << 4) | 0x8;
                            $bc = ($bq << 4) | 0x8;
                            $hex = self::rgb_hex($rc, $gc, $bc);
                            $counts[$hex] = ($counts[$hex] ?? 0) + 1;
                        }
                    }
                    imagedestroy($tmp);
                    imagedestroy($src);
                    if ($counts) {
                        arsort($counts);
                        $hexes = array_keys($counts);
                        $filtered = self::filter_similar_colors($hexes, $k);
                        return [$filtered[0], $filtered];
                    }
                }
            }
        }

        // last resort
        return ['#ffffff', ['#ffffff']];
    }

    /**
     * Filter palette to ensure minimum perceptual distance between colors.
     * Uses Delta-E (CIE76) in LAB color space for perceptual accuracy.
     *
     * @param array $hexes   Array of hex colors (sorted by frequency)
     * @param int   $k       Maximum colors to return
     * @param float $min_distance Minimum Delta-E distance (default: 20)
     * @return array Filtered hex colors
     */
    private static function filter_similar_colors(array $hexes, int $k = 5, float $min_distance = 20.0): array
    {
        if (empty($hexes)) return [];

        $filtered = [];
        $filtered[] = $hexes[0]; // Always keep the primary color

        foreach ($hexes as $hex) {
            if (count($filtered) >= $k) break;

            // Check distance to all already-selected colors
            $too_close = false;
            foreach ($filtered as $existing) {
                $distance = self::color_distance($hex, $existing);
                if ($distance < $min_distance) {
                    $too_close = true;
                    break;
                }
            }

            if (!$too_close) {
                $filtered[] = $hex;
            }
        }

        return $filtered;
    }

    /**
     * Calculate perceptual color distance using Delta-E (CIE76) in LAB space.
     * Simplified implementation for performance.
     *
     * @param string $hex1 First hex color
     * @param string $hex2 Second hex color
     * @return float Distance (0-100+, typically 0-50 for similar colors)
     */
    private static function color_distance(string $hex1, string $hex2): float
    {
        $rgb1 = self::hex_to_rgb($hex1);
        $rgb2 = self::hex_to_rgb($hex2);

        // Convert RGB to LAB (simplified)
        $lab1 = self::rgb_to_lab($rgb1);
        $lab2 = self::rgb_to_lab($rgb2);

        // Delta-E (CIE76) = sqrt((L2-L1)^2 + (a2-a1)^2 + (b2-b1)^2)
        $dL = $lab2[0] - $lab1[0];
        $da = $lab2[1] - $lab1[1];
        $db = $lab2[2] - $lab1[2];

        return sqrt($dL * $dL + $da * $da + $db * $db);
    }

    /**
     * Convert hex to RGB array.
     */
    private static function hex_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Convert RGB to LAB color space (simplified).
     * Full conversion: RGB -> XYZ -> LAB
     */
    private static function rgb_to_lab(array $rgb): array
    {
        // Normalize RGB to 0-1
        $r = $rgb[0] / 255.0;
        $g = $rgb[1] / 255.0;
        $b = $rgb[2] / 255.0;

        // Apply gamma correction
        $r = ($r > 0.04045) ? pow(($r + 0.055) / 1.055, 2.4) : $r / 12.92;
        $g = ($g > 0.04045) ? pow(($g + 0.055) / 1.055, 2.4) : $g / 12.92;
        $b = ($b > 0.04045) ? pow(($b + 0.055) / 1.055, 2.4) : $b / 12.92;

        // Convert to XYZ (D65 illuminant)
        $x = $r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375;
        $y = $r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750;
        $z = $r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041;

        // Normalize for D65 white point
        $x = $x / 0.95047;
        $y = $y / 1.00000;
        $z = $z / 1.08883;

        // Convert to LAB
        $fx = ($x > 0.008856) ? pow($x, 1/3) : (7.787 * $x + 16/116);
        $fy = ($y > 0.008856) ? pow($y, 1/3) : (7.787 * $y + 16/116);
        $fz = ($z > 0.008856) ? pow($z, 1/3) : (7.787 * $z + 16/116);

        $L = 116 * $fy - 16;
        $a = 500 * ($fx - $fy);
        $b = 200 * ($fy - $fz);

        return [$L, $a, $b];
    }

    private static function rgb_hex(int $r, int $g, int $b): string
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}