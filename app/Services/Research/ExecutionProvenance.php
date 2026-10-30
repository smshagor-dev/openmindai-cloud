<?php

namespace App\Services\Research;

class ExecutionProvenance
{
    public static function runtime(array $provenance): bool
    {
        if (($provenance['isolation'] ?? '') === 'firecracker_microvm') {
            $snapshot = (string) config('research.worker_snapshot');
            $digest = (string) config('research.worker_runtime_digest');

            return preg_match('/^snap_[A-Za-z0-9]+$/', $snapshot) === 1
                && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1
                && hash_equals($snapshot, (string) ($provenance['snapshot_id'] ?? ''))
                && hash_equals($digest, (string) ($provenance['runtime_digest'] ?? ''))
                && ($provenance['network'] ?? '') === 'none';
        }

        return preg_match('/^sha256:[a-f0-9]{64}$/', $provenance['image'] ?? '') === 1;
    }
}
