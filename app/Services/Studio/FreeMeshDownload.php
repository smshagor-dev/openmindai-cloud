<?php

namespace App\Services\Studio;

class FreeMeshDownload extends HostedMeshDownload
{
    protected function allowed(array $parts): bool
    {
        return ($parts['host'] ?? '') === 'microsoft-trellis-2.hf.space'
            && str_starts_with($parts['path'] ?? '', '/gradio_api/file=')
            && str_ends_with($parts['path'] ?? '', '.glb') && ! isset($parts['query']);
    }
}
