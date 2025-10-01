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
                $hexes = array_slice(array_keys($counts), 0, max(1, $k));
                return [$hexes[0] ?? '#ffffff', $hexes];
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
                        $hexes = array_slice(array_keys($counts), 0, max(1, $k));
                        return [$hexes[0], $hexes];
                    }
                }
            }
        }

        // last resort
        return ['#ffffff', ['#ffffff']];
    }

    private static function rgb_hex(int $r, int $g, int $b): string
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}