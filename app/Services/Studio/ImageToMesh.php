<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Image-to-3D through NVIDIA's hosted TRELLIS endpoint. Inference runs on
 * NVIDIA's GPUs, so cPanel only sends the image and stores the returned GLB.
 */
class ImageToMesh
{
    public const MODEL = 'microsoft/trellis';

    public function available(): bool
    {
        return (bool) config('studio.image_model.custom_images') && (string) config('studio.image_model.key') !== '' && self::safeUrl((string) config('studio.image_model.url'));
    }

    /** @return array{glb: string, model: string} */
    public function generate(string $imageDataUrl, int $seed = 0): array
    {
        abort_unless($this->available(), 503, 'Image to 3D needs NVIDIA_API_KEY on the server.');
        abort_unless(preg_match('~^data:image/(jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$~D', $imageDataUrl), 422, 'The reference image preview is invalid.');
        $deadline = microtime(true) + (int) config('studio.image_model.timeout', 170);
        // Defaults follow the TRELLIS image pipeline (sparse structure 7.5 / 12, SLAT 3 / 12).
        $response = $this->client($deadline)->post((string) config('studio.image_model.url'), [
            'image' => $imageDataUrl,
            'seed' => $seed,
            'ss_cfg_scale' => 7.5,
            'ss_sampling_steps' => 12,
            'slat_cfg_scale' => 3,
            'slat_sampling_steps' => 12,
        ]);
        // NVIDIA Cloud Functions answer long requests with 202 and a request ID to poll.
        while ($response->status() === 202) {
            $request = $response->header('NVCF-REQID');
            if (! preg_match('/^[a-f0-9-]{36}$/i', $request) || microtime(true) > $deadline) {
                throw new RuntimeException('Image to 3D did not finish in time. Try again later.');
            }
            if (! app()->runningUnitTests()) {
                usleep(2000000);
            }
            $response = $this->client($deadline)->get('https://api.nvcf.nvidia.com/v2/nvcf/pexec/status/'.$request);
        }
        if (! $response->successful()) {
            throw new RuntimeException(match (true) {
                in_array($response->status(), [401, 403], true) => 'The NVIDIA key cannot use image to 3D (HTTP '.$response->status().').',
                $response->status() === 429 => 'NVIDIA image to 3D is rate limited. Try again in a minute.',
                default => 'NVIDIA image to 3D is unavailable (HTTP '.$response->status().').',
            });
        }

        return ['glb' => $this->glb($response->body(), (string) $response->header('Content-Type')), 'model' => self::MODEL];
    }

    /** Accepts a binary GLB or the JSON artifact forms used by NVIDIA genai endpoints. */
    private function glb(string $body, string $type): string
    {
        $max = (int) config('studio.image_model.max_bytes');
        if (str_starts_with($body, 'glTF')) {
            return $this->validated($body, $max);
        }
        abort_if(strlen($body) > (int) ($max * 1.4) + 4096, 502, 'The 3D model is too large to store.');
        $json = json_decode($body, true);
        $artifact = is_array($json) ? ($json['artifacts'][0] ?? $json) : null;
        if (! is_array($artifact) || (($artifact['finishReason'] ?? 'SUCCESS') !== 'SUCCESS')) {
            throw new RuntimeException('NVIDIA did not return a 3D model'.(is_array($artifact) && isset($artifact['finishReason']) ? ' ('.$artifact['finishReason'].').' : '.'));
        }
        $encoded = $artifact['base64'] ?? $artifact['b64_json'] ?? $artifact['glb'] ?? $artifact['data'] ?? null;
        if (is_string($encoded) && str_starts_with($encoded, 'data:')) {
            $encoded = substr($encoded, strpos($encoded, ',') + 1);
        }
        $binary = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($binary === false) {
            throw new RuntimeException('NVIDIA returned an unreadable 3D model.');
        }

        return $this->validated($binary, $max);
    }

    /** glTF 2.0 binary header: magic "glTF", version 2, total length matching the bytes. */
    private function validated(string $glb, int $max): string
    {
        abort_if(strlen($glb) > $max, 502, 'The 3D model is too large to store.');
        $header = strlen($glb) >= 20 ? unpack('a4magic/Vversion/Vlength', substr($glb, 0, 12)) : null;
        if (! $header || $header['magic'] !== 'glTF' || $header['version'] !== 2 || $header['length'] !== strlen($glb)) {
            throw new RuntimeException('NVIDIA returned an invalid GLB model.');
        }

        return $glb;
    }

    private function client(float $deadline)
    {
        return Http::withToken((string) config('studio.image_model.key'))
            ->accept('application/json')->connectTimeout(10)
            ->timeout(max(5, (int) ceil($deadline - microtime(true))))
            ->withOptions(['allow_redirects' => false]);
    }

    private static function safeUrl(string $url): bool
    {
        return (bool) preg_match('~^https://ai\.api\.nvidia\.com/v1/genai/[a-z0-9._/-]+$~', $url);
    }
}
