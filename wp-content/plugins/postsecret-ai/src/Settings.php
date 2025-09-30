<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class Settings
{
    const OPTION = 'psai_env'; // single array option

    public static function register(): void
    {
        register_setting(
            'postsecret-ai',
            self::OPTION,
            ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitizeAll']]
        );

        // Create sections in logical order
        $sections = Schema::sections();
        foreach (['api', 'model', 'moderation', 'http', 'logging', 'encoding', 'ingest', 'paths'] as $sid) {
            if (!isset($sections[$sid])) continue;
            add_settings_section(
                'psai_' . $sid,
                esc_html($sections[$sid]['title']),
                function () use ($sections, $sid) {
                    echo '<p>' . esc_html($sections[$sid]['desc']) . '</p>';
                },
                'postsecret-ai'
            );
        }

        // Add fields grouped by section, ordered by 'order'
        $specs = Schema::get();
        usort($specs, function ($a, $b) {
            return [$a['section'], $a['order'] ?? 999] <=> [$b['section'], $b['order'] ?? 999];
        });

        foreach ($specs as $spec) {
            add_settings_field(
                $spec['key'],
                esc_html($spec['label']),
                [self::class, 'renderField'],
                'postsecret-ai',
                'psai_' . $spec['section'],
                ['spec' => $spec]
            );
        }
    }

    /** Sanitize the full array */
    public static function sanitizeAll($input)
    {
        $out = [];
        $defs = self::defaults();

        foreach (Schema::get() as $spec) {
            $k = $spec['key'];
            $v = $input[$k] ?? $defs[$k];
            $out[$k] = self::sanitizeOne($spec, $v);
        }

        // --- Minimal validation & user feedback ---
        if (empty($out['API_KEY'])) {
            add_settings_error('postsecret-ai', 'psai_api_key_missing', 'API Key is required to call OpenAI.', 'error');
        }
        if (!empty($out['REQUEST_TIMEOUT_SECONDS']) && (int)$out['REQUEST_TIMEOUT_SECONDS'] < 5) {
            add_settings_error('postsecret-ai', 'psai_timeout_low', 'HTTP Timeout seems low; consider ≥ 5 seconds.', 'warning');
        }
        if (!empty($out['MODEL_NAME']) && !is_string($out['MODEL_NAME'])) {
            add_settings_error('postsecret-ai', 'psai_model_bad', 'Model Name must be a string.', 'error');
        }

        // You could block saving by returning the old value when critical errors occur.
        // For now we still save, but show errors/warnings.
        return $out;
    }

    /** @return array<string,mixed> defaults by key */
    public static function defaults(): array
    {
        $d = [];
        foreach (Schema::get() as $spec) $d[$spec['key']] = $spec['default'] ?? '';
        return $d;
    }

    /** Sanitize a single field based on kind + min/max */
    private static function sanitizeOne(array $spec, $v)
    {
        $kind = $spec['kind'];
        switch ($kind) {
            case 'bool':
                $val = (bool)$v;
                break;
            case 'int':
                $val = is_numeric($v) ? (int)$v : (int)($spec['default'] ?? 0);
                break;
            case 'float':
                $val = is_numeric($v) ? (float)$v : (float)($spec['default'] ?? 0.0);
                break;
            case 'choice':
                $choices = $spec['choices'] ?? [];
                $val = in_array($v, $choices, true) ? $v : ($spec['default'] ?? ($choices[0] ?? ''));
                break;
            case 'path':
            case 'str':
            default:
                $val = is_string($v) ? trim($v) : '';
                break;
        }
        if (isset($spec['min']) && is_numeric($spec['min']) && is_numeric($val)) $val = max($val, $spec['min']);
        if (isset($spec['max']) && is_numeric($spec['max']) && is_numeric($val)) $val = min($val, $spec['max']);
        return $val;
    }

    /** Render a single field row */
    public static function renderField(array $args): void
    {
        $spec = $args['spec'];
        $key = $spec['key'];
        $val = get_option(self::OPTION, []);
        $cur = $val[$key] ?? ($spec['default'] ?? '');

        $name = self::OPTION . '[' . esc_attr($key) . ']';
        $desc = !empty($spec['help']) ? '<p class="description">' . esc_html($spec['help']) . '</p>' : '';
        $secret = !empty($spec['secret']);
        $choices = $spec['choices'] ?? [];

        switch ($spec['kind']) {
            case 'bool':
                echo '<label><input type="checkbox" name="' . $name . '" value="1" ' . checked((bool)$cur, true, false) . ' /> Enable</label>' . $desc;
                break;

            case 'choice':
                echo '<select name="' . $name . '">';
                foreach ($choices as $c) {
                    echo '<option value="' . esc_attr($c) . '" ' . selected($cur, $c, false) . '>' . esc_html($c) . '</option>';
                }
                echo '</select>' . $desc;
                break;

            case 'int':
            case 'float':
                $step = $spec['kind'] === 'int' ? '1' : 'any';
                $min = isset($spec['min']) ? ' min="' . esc_attr($spec['min']) . '"' : '';
                $max = isset($spec['max']) ? ' max="' . esc_attr($spec['max']) . '"' : '';
                echo '<input type="number" name="' . $name . '" value="' . esc_attr($cur) . '" step="' . $step . '"' . $min . $max . ' />';
                echo $desc;
                break;

            case 'path':
                echo '<input type="text" name="' . $name . '" value="' . esc_attr($cur) . '" style="width:420px" />';
                $hint = !empty($spec['path_kind']) ? ' (' . $spec['path_kind'] . ')' : '';
                echo '<p class="description">Path' . $hint . '. ' . $spec['help'] . '</p>';
                break;

            case 'str':
            default:
                $type = $secret ? 'password' : 'text';
                echo '<input type="' . $type . '" name="' . $name . '" value="' . esc_attr($cur) . '" style="width:420px" autocomplete="new-password" />';
                echo $desc;
                break;
        }
    }
}