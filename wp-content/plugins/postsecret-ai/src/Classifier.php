<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class Classifier
{
    public static function classify(string $apiKey, string $model, string $imageUrl): array
    {
        $endpoint = 'https://api.openai.com/v1/chat/completions';

        // Minimal JSON input the prompt expects; we're not using OCR yet.
        $input = [
            'sentences' => [],
            'text' => '',
            'meta' => ['ocrQuality' => 0.0],
        ];

        $messages = [
            ['role' => 'system', 'content' => Prompt::TEXT],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => wp_json_encode($input, JSON_UNESCAPED_SLASHES)],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ],
            ],
        ];

        $body = [
            'model' => $model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => $messages,
        ];

        $res = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60,
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($res)) throw new \RuntimeException($res->get_error_message());

        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        if ($code >= 300) throw new \RuntimeException('OpenAI HTTP ' . $code . ': ' . substr($raw, 0, 400));

        $json = json_decode($raw, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        $payload = json_decode($content, true);
        if (!is_array($payload)) throw new \RuntimeException('Unexpected model response.');

        // Light normalization (keep hands off beyond formatting)
        if (isset($payload['tags']) && is_array($payload['tags'])) {
            $payload['tags'] = array_values(array_unique(array_map(
                fn($t) => trim(mb_strtolower((string)$t)),
                $payload['tags']
            )));
            sort($payload['tags']);
        }

        return $payload;
    }
}