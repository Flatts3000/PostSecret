<?php
/**
 * PSAI\Classifier
 * -----------------------------------------------------------------------------
 * Vision classification client for OpenAI-compatible chat models.
 *
 * Responsibilities:
 * - Pull runtime options from WordPress Settings (psai_env) with safe defaults.
 * - Build a deterministic, JSON-only chat request (images + prompt).
 * - Perform resilient HTTP requests with bounded exponential backoff and jitter.
 * - Parse and normalize the model's JSON response into the project schema.
 * - Optionally run a moderation pass (non-blocking) and annotate results.
 *
 * Notable choices:
 * - Centralized HTTP POST helper with retry on 429/5xx and support for Retry-After.
 * - Strict JSON decoding with failure surfacing (never silently accepts bad shapes).
 * - Headers allow optional org/project ids if provided in Settings.
 * - Response format uses `json_object` to keep providers compatible; switchable.
 *
 * External behavior:
 * - API base, model, sampling, and HTTP knobs are still read from Settings.
 * - Falls back to provided function params only when non-empty; otherwise Settings.
 *
 * @package PSAI
 */

declare(strict_types=1);

namespace PSAI;

if (!defined('ABSPATH')) {
    exit;
}

final class Classifier
{
    private const UA = 'PostSecret-Classifier/1.0 (+WordPress)';
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const MAX_BACKOFF_SECONDS = 8.0;

    /**
     * Classify a postcard (front + optional back) using a vision-capable chat model.
     *
     * If $apiKey or $model are empty, values are taken from Settings (psai_env).
     *
     * @param string $apiKey OpenAI-compatible API key (optional; Settings fallback)
     * @param string $model Chat model (optional; Settings fallback, default gpt-4o-mini)
     * @param string $frontUrl Public URL for the front image
     * @param string|null $backUrl Public URL for the back image (optional)
     * @return array                Normalized schema payload
     *
     * @throws \RuntimeException on configuration or hard HTTP/JSON failures
     */
    public static function classify(string $apiKey, string $model, string $frontUrl, ?string $backUrl = null): array
    {
        $opts = get_option(Settings::OPTION, []) ?: [];

        // ── Credentials / endpoint
        $apiKey = $apiKey !== '' ? $apiKey : (string)($opts['API_KEY'] ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException('API key is not configured.');
        }
        $base = self::apiBase($opts); // e.g. https://api.openai.com/v1 (or compatible)
        $endpoint = rtrim($base, '/') . '/chat/completions';

        // ── Model & sampling
        $model = $model !== '' ? $model : (string)($opts['MODEL_NAME'] ?? self::DEFAULT_MODEL);
        $temperature = self::optFloat($opts, 'TEMPERATURE', 0.2);
        $topP = self::optFloat($opts, 'TOP_P', 1.0);
        $maxTokens = self::optInt($opts, 'MAX_TOKENS', 1200, 1);
        $frequency = self::optFloat($opts, 'FREQUENCY_PENALTY', 0.0);
        $presence = self::optFloat($opts, 'PRESENCE_PENALTY', 0.0);
        $seed = isset($opts['SEED']) && $opts['SEED'] !== '' ? (int)$opts['SEED'] : null;

        // ── Vision options
        $vision_detail = $opts['VISION_DETAIL'] ?? 'high';
        $detail = in_array($vision_detail, ['low', 'auto', 'high'], true)
            ? (string)$vision_detail
            : 'high';

        // ── HTTP knobs
        $timeout = self::optInt($opts, 'REQUEST_TIMEOUT_SECONDS', 60, 5);
        $retries = self::optInt($opts, 'REQUEST_MAX_RETRIES', 3, 0);
        $backoff = max(0.0, (float)($opts['REQUEST_BACKOFF_FACTOR'] ?? 0.5));
        $ssl = self::optBool($opts, 'SSL_VERIFY', true);

        // ── Headers (support org/project if configured)
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => self::UA,
        ];
        if (!empty($opts['OPENAI_ORG'])) {
            $headers['OpenAI-Organization'] = (string)$opts['OPENAI_ORG'];
        }
        if (!empty($opts['OPENAI_PROJECT'])) {
            $headers['OpenAI-Project'] = (string)$opts['OPENAI_PROJECT'];
        }

        // ── Messages (use custom prompt if configured, else built-in)
        $messages = [
            ['role' => 'system', 'content' => Prompt::get()],
            ['role' => 'user', 'content' => self::buildVisionContent($frontUrl, $backUrl, $detail)],
        ];

        // ── Response format (default json_object for broad compatibility)
        $responseFormat = ['type' => 'json_object'];
        if (!empty($opts['RESPONSE_FORMAT']) && $opts['RESPONSE_FORMAT'] === 'json_schema' && !empty(Prompt::SCHEMA)) {
            // If your provider supports json_schema, you may toggle it from Settings.
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'postsecret_schema',
                    'schema' => Prompt::SCHEMA,
                    'strict' => true,
                ],
            ];
        }

        // ── Body
        $body = array_filter([
            'model' => $model,
            'temperature' => $temperature,
            'top_p' => $topP,
            'max_tokens' => $maxTokens,
            'frequency_penalty' => $frequency,
            'presence_penalty' => $presence,
            'seed' => $seed,
            'response_format' => $responseFormat,
            'messages' => $messages,
        ], static fn($v) => $v !== null);

        // ── Request (with retries)
        $raw = self::httpPostJson(
            url: $endpoint,
            headers: $headers,
            body: $body,
            timeout: $timeout,
            retries: $retries,
            backoffFactor: $backoff,
            sslVerify: $ssl
        );

        // ── Parse chat response
        $json = self::decodeJson($raw);
        $content = (string)($json['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('Unexpected model response (empty content).');
        }

        $payload = self::decodeJson($content);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected model response (no JSON payload).');
        }

        // ── Optional moderation (non-blocking)
        $moderated = self::maybeModerate(
            base: $base,
            apiKey: $apiKey,
            opts: $opts,
            text: (string)$content,
            timeout: $timeout,
            sslVerify: $ssl
        );
        if ($moderated !== null) {
            $payload['_moderation'] = $moderated;
        }

        // ── Normalize to project schema
        return \PSAI\SchemaGuard::normalize($payload);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the API base URL; appends /v1 if a custom base without version is provided.
     *
     * @param array $opts
     * @return string
     */
    private static function apiBase(array $opts): string
    {
        $base = trim((string)($opts['API_BASE'] ?? ''));
        if ($base === '') {
            return 'https://api.openai.com/v1';
        }
        $base = rtrim($base, '/');
        // If caller already provides .../v1 keep it, else add /v1 for OpenAI-compatible layout.
        return preg_match('~/v\d+$~', $base) ? $base : ($base . '/v1');
    }

    /**
     * Build multi-part user content for vision inputs.
     *
     * @param string $frontUrl
     * @param string|null $backUrl
     * @param string $detail low|auto|high
     * @return array<int, array<string,mixed>>
     */
    private static function buildVisionContent(string $frontUrl, ?string $backUrl, string $detail): array
    {
        $allowed_details = ['low', 'high', 'auto'];
        if (!in_array($detail, $allowed_details, true)) {
            $detail = 'high';
        }

        $content = [
            ['type' => 'text', 'text' => 'SIDE: front'],
            ['type' => 'image_url', 'image_url' => ['url' => $frontUrl, 'detail' => $detail]],
        ];

        if ($backUrl) {
            $content[] = ['type' => 'text', 'text' => 'SIDE: back'];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $backUrl, 'detail' => $detail]];
        }

        return $content;
    }

    /**
     * HTTP POST with JSON body, retries on 429/5xx, honors Retry-After, with jitter.
     *
     * @param string $url
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     * @param int $timeout
     * @param int $retries
     * @param float $backoffFactor
     * @param bool $sslVerify
     * @return string Raw body
     */
    private static function httpPostJson(
        string $url,
        array  $headers,
        array  $body,
        int    $timeout,
        int    $retries,
        float  $backoffFactor,
        bool   $sslVerify
    ): string
    {
        $attempt = 0;

        do {
            $attempt++;

            $res = wp_remote_post($url, [
                'headers' => $headers,
                'timeout' => $timeout,
                'sslverify' => $sslVerify,
                'body' => wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            if (is_wp_error($res)) {
                if ($attempt > $retries) {
                    throw new \RuntimeException('HTTP error: ' . $res->get_error_message());
                }
                self::sleepBackoff($attempt, $backoffFactor);
                continue;
            }

            $code = (int)wp_remote_retrieve_response_code($res);
            $raw = (string)wp_remote_retrieve_body($res);

            if ($code >= 200 && $code < 300) {
                return $raw;
            }

            // Retry policy for 429/5xx
            if (($code === 429 || $code >= 500) && $attempt <= $retries) {
                // Respect Retry-After if present (seconds)
                $retryAfter = wp_remote_retrieve_header($res, 'retry-after');
                if (is_string($retryAfter) && $retryAfter !== '') {
                    $sec = (float)$retryAfter;
                    $sec = min(max($sec, 0.0), self::MAX_BACKOFF_SECONDS);
                    if ($sec > 0) {
                        usleep((int)round($sec * 1_000_000));
                        continue;
                    }
                }
                self::sleepBackoff($attempt, $backoffFactor);
                continue;
            }

            // Hard fail for non-retriable codes
            throw new \RuntimeException('HTTP ' . $code . ': ' . substr($raw, 0, 800));
        } while ($attempt <= $retries);

        throw new \RuntimeException('Exhausted retries.');
    }

    /**
     * Basic exponential backoff with jitter.
     *
     * @param int $attempt
     * @param float $factor
     * @return void
     */
    private static function sleepBackoff(int $attempt, float $factor): void
    {
        if ($factor <= 0) {
            return;
        }
        $base = $factor * (2 ** max(0, $attempt - 1));
        $base = min($base, self::MAX_BACKOFF_SECONDS);
        // Full jitter (0..base)
        $secs = mt_rand() / mt_getrandmax() * $base;
        if ($secs > 0) {
            usleep((int)round($secs * 1_000_000));
        }
    }

    /**
     * Optional moderation pass (non-blocking). Returns array if available, else null.
     *
     * @param string $base
     * @param string $apiKey
     * @param array $opts
     * @param string $text
     * @param int $timeout
     * @param bool $sslVerify
     * @return array<string,mixed>|null
     */
    private static function maybeModerate(string $base, string $apiKey, array $opts, string $text, int $timeout, bool $sslVerify): ?array
    {
        $enabled = self::optBool($opts, 'MODERATION_ENABLE', false);
        if (!$enabled || $text === '') {
            return null;
        }

        $endpoint = rtrim($base, '/') . '/moderations';
        $model = (string)($opts['MODERATION_MODEL'] ?? 'omni-moderation-latest');

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => self::UA,
        ];

        try {
            $raw = self::httpPostJson(
                url: $endpoint,
                headers: $headers,
                body: ['model' => $model, 'input' => $text],
                timeout: $timeout,
                retries: 0,                // keep it fast & non-blocking
                backoffFactor: 0.0,
                sslVerify: $sslVerify
            );
            $json = self::decodeJson($raw);
            return $json;
        } catch (\Throwable $e) {
            // Non-fatal; annotate error for observability
            return ['error' => 'moderation_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Decode JSON into array with robust flags and clear errors.
     *
     * @param string $raw
     * @return array<string,mixed>
     */
    private static function decodeJson(string $raw): array
    {
        // Avoid JSON_THROW_ON_ERROR to keep PHP 7.x compatibility in some WP installs.
        $data = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($data)) {
            $msg = function_exists('json_last_error_msg') ? json_last_error_msg() : 'invalid_json';
            throw new \RuntimeException('Invalid JSON: ' . $msg);
        }
        return $data;
    }

    /**
     * Typed option helpers (with sane bounds).
     */
    private static function optInt(array $opts, string $key, int $default, int $min = PHP_INT_MIN, ?int $max = null): int
    {
        if (!isset($opts[$key]) || $opts[$key] === '') {
            return $default;
        }
        $val = (int)$opts[$key];
        $val = max($val, $min);
        if ($max !== null) {
            $val = min($val, $max);
        }
        return $val;
    }

    private static function optFloat(array $opts, string $key, float $default, float $min = -INF, ?float $max = null): float
    {
        if (!isset($opts[$key]) || $opts[$key] === '') {
            return $default;
        }
        $val = (float)$opts[$key];
        $val = max($val, $min);
        if ($max !== null) {
            $val = min($val, $max);
        }
        return $val;
    }

    private static function optBool(array $opts, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $opts)) {
            return $default;
        }
        $v = $opts[$key];
        if (is_bool($v)) {
            return $v;
        }
        // Accept "1"/"0", "true"/"false"
        if (is_string($v)) {
            $lv = strtolower(trim($v));
            if ($lv === '1' || $lv === 'true' || $lv === 'yes' || $lv === 'on') {
                return true;
            }
            if ($lv === '0' || $lv === 'false' || $lv === 'no' || $lv === 'off') {
                return false;
            }
        }
        return (bool)$v;
    }
}