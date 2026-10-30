<?php

namespace App\Services\Studio;

use App\Services\HostedCodeAgents;

class SceneModel
{
    /**
     * The hosted chat model that writes procedural and architecture scenes.
     * Scenes need no GPU worker, so they work on shared hosting such as cPanel.
     * OpenMindAI scene writers come first (Ultra, Super, Lite), a passed admin
     * test before an untested one (a fresh install has none tested); a model
     * whose test failed is skipped.
     */
    public function pick(): ?object
    {
        if (strlen((string) config('models.nvidia_key')) < 12) {
            return null;
        }
        $preference = ['openmindai-ultra', 'openmindai-super', 'openmindai-lite'];
        $usable = app(HostedCodeAgents::class)->enabled()
            ->filter(fn ($m) => in_array($m->last_test_status, ['success', 'not_tested', null], true))
            ->sortBy(fn ($m) => [array_search($m->slug, $preference, true) === false ? 9 : array_search($m->slug, $preference, true), $m->last_test_status === 'success' ? 0 : 1]);

        return $usable->first();
    }
}
