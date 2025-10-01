<?php

namespace PSAI;

if (!defined('ABSPATH')) exit;

class Classifier
{
    public static function classify(string $apiKey, string $model, string $frontUrl, ?string $backUrl = null): array
    {
        $endpoint = 'https://api.openai.com/v1/chat/completions';

        // Side markers immediately precede each image.
        $userContent = [
            ['type' => 'text', 'text' => 'SIDE: front'],
            ['type' => 'image_url', 'image_url' => ['url' => $frontUrl, 'detail' => 'high']],
        ];
        if ($backUrl) {
            $userContent[] = ['type' => 'text', 'text' => 'SIDE: back'];
            $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $backUrl, 'detail' => 'high']];
        }

        $messages = [
            ['role' => 'system', 'content' => Prompt::TEXT],
            ['role' => 'user', 'content' => $userContent],
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
            'timeout' => (int)(get_option('psai_env')['REQUEST_TIMEOUT_SECONDS'] ?? 60),
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($res)) throw new \RuntimeException($res->get_error_message());
        $code = wp_remote_retrieve_response_code($res);
        $raw = wp_remote_retrieve_body($res);
        if ($code >= 300) throw new \RuntimeException('OpenAI HTTP ' . $code . ': ' . substr($raw, 0, 500));

        $json = json_decode($raw, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        $payload = json_decode($content, true);
        if (!is_array($payload)) throw new \RuntimeException('Unexpected model response.');

        $payload = \PSAI\SchemaGuard::normalize($payload);

        return $payload;
    }
}