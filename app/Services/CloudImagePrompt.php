<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CloudImagePrompt
{
    public function normalize(string $prompt): string
    {
        // The actual user's input is retained in the task and never replaced.
        // English can improve text-to-image model adherence for code-mixed prompts.
        // Preserve quoted text, names and creative requirements; on error use original.
        $needsBridge = preg_match('/[^\x00-\x7F]/', $prompt)
            || preg_match('/\b(amar|amake|ekta|akta|koro|korba|banao|banaw|chobi|chhobi|chobi|lagbe|daw|dao|hobe|jemon)\b/i', $prompt);
        $key = (string) config('cloud_images.key');
        $model = (string) config('cloud_images.prompt_model');
        if (! $needsBridge || strlen($key) < 12 || ! $model) {
            return $prompt;
        }

        try {
            $reply = Http::withToken($key)->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Translate the user image description into accurate concise English for an image generator. Support Bengali, Banglish, mixed language and any script. Preserve all visual details, negation, proper nouns and exact quoted text. No extra imagery, no commentary. Return only the English image prompt.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'stream' => false, 'max_tokens' => 700, 'temperature' => 0,
                ]);
            $output = $reply->successful() ? $reply->json('choices.0.message.content') : null;
            if (is_string($output) && trim($output) !== '' && mb_strlen($output) <= 6000) {
                return trim($output);
            }
        } catch (\Throwable) {
            // Translation must not make generation completely unavailable.
        }

        return $prompt;
    }
}
