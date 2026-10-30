<?php

namespace App\Services;

/** Bounded inline images only: the provider never fetches client-supplied URLs. */
final class MobileVisionPayload
{
    public static function supports(object $model): bool
    {
        return $model->api_protocol === 'chat' && in_array($model->provider_model, [
            'meta/llama-3.2-11b-vision-instruct',
            'meta/llama-3.2-90b-vision-instruct',
        ], true);
    }

    public static function validate(array $messages): bool
    {
        $images = 0;
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            abort_unless($message['role'] === 'user' && array_is_list($content)
                && count($content) >= 1 && count($content) <= 5, 422, 'Invalid image message.');
            foreach ($content as $part) {
                abort_unless(is_array($part), 422, 'Invalid image content part.');
                if (($part['type'] ?? '') === 'text') {
                    abort_unless(is_string($part['text'] ?? null) && mb_strlen($part['text']) <= 16000
                        && array_diff(array_keys($part), ['type', 'text']) === [], 422, 'Invalid image prompt text.');

                    continue;
                }
                abort_unless(($part['type'] ?? '') === 'image_url'
                    && array_diff(array_keys($part), ['type', 'image_url']) === []
                    && is_array($part['image_url'] ?? null)
                    && array_keys($part['image_url']) === ['url'], 422, 'Invalid image content part.');
                $url = $part['image_url']['url'];
                abort_unless(is_string($url) && strlen($url) <= 175000
                    && preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/]+={0,2})$#D', $url, $matches),
                    422, 'Images must be inline JPEG, PNG or WebP data.');
                $bytes = base64_decode($matches[2], true);
                abort_unless($bytes !== false && strlen($bytes) <= 131072, 422, 'Image is too large.');
                $info = @getimagesizefromstring($bytes);
                abort_unless($info && $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= 16777216
                    && ($info['mime'] ?? '') === 'image/'.$matches[1], 422, 'Invalid image bytes.');
                abort_if(++$images > 4, 422, 'At most four images may be sent per request.');
            }
        }

        return $images > 0;
    }

    /** Llama Vision accepts user/assistant turns with images, not system turns. */
    public static function providerMessages(array $messages): array
    {
        $messages = MultilingualPrompt::apply($messages);
        $instructions = [];
        $turns = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $instructions[] = $message['content'];
            } else {
                abort_unless(in_array($message['role'], ['user', 'assistant'], true),
                    422, 'Vision supports user and assistant conversation turns.');
                $turns[] = $message;
            }
        }
        foreach ($turns as &$turn) {
            if ($turn['role'] !== 'user' || ! is_array($turn['content'])) {
                continue;
            }
            array_unshift($turn['content'], ['type' => 'text', 'text' => implode("\n\n", $instructions)]);
            break;
        }

        return $turns;
    }
}
