<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CloudImagePrompt
{
    public function normalize(string $prompt): string
    {
        $key = (string) config('cloud_images.key');
        $needsRewrite = strlen($prompt) > 180 || str_contains($prompt, "\n")
            || preg_match('/[^\x00-\x7F]|\b(amar|amake|ekta|akta|koro|korba|banao|banaw|chobi|chhobi|lagbe|daw|dao|hobe|jemon)\b/i', $prompt);
        if (! $needsRewrite || strlen($key) < 12) {
            return $prompt;
        }

        // Both attempts together are capped at 80s, within the 900s image job budget.
        $primary = (string) config('cloud_images.prompt_model');
        $models = [$primary => max(20, min(60, (int) config('cloud_images.prompt_timeout', 60)))];
        $models += ['nvidia/nemotron-3.5-lightning-30b-a3b' => 20];
        unset($models['']);
        foreach ($models as $model => $timeout) {
            $output = $this->rewrite($prompt, $key, $model, $timeout);
            if ($output !== null) {
                return $output;
            }
        }

        return $prompt;
    }

    private function rewrite(string $prompt, string $key, string $model, int $timeout): ?string
    {
        try {
            $reply = Http::withToken($key)->connectTimeout(5)->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Prepare the user image description for an image generator. Translate non-English instructions into English and clarify complex requirements without changing their meaning. Keep clear English prompts as written. Preserve all visual details, layout, quantities, negations, names and exact quoted text. No extra imagery or commentary. Return only the image prompt.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'stream' => false, 'max_tokens' => 1600, 'temperature' => 0,
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ]);
            $output = $reply->successful() && $reply->json('choices.0.finish_reason') !== 'length' ? $reply->json('choices.0.message.content') : null;
            if (is_string($output) && trim($output) !== '' && mb_strlen($output) <= 6000) {
                return trim($output);
            }
        } catch (\Throwable) {
            // Translation must not make generation completely unavailable.
        }

        return null;
    }
}
