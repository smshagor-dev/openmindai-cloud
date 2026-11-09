<?php

namespace App\Services\Studio;

use UnexpectedValueException;

/** Bounded structural checks for a self-contained GLB with actual mesh primitives. */
class GlbMesh
{
    public function validate(string $bytes): void
    {
        $invalid = fn () => throw new UnexpectedValueException('The provider did not return a self-contained GLB mesh.');
        $size = strlen($bytes);
        if ($size < 28 || $size > (int) config('studio.image_model.max_bytes', 25000000)) {
            $invalid();
        }
        $header = unpack('a4magic/Vversion/Vlength', substr($bytes, 0, 12));
        if ($header['magic'] !== 'glTF' || $header['version'] !== 2 || $header['length'] !== $size) {
            $invalid();
        }
        $offset = 12;
        $document = null;
        $binary = 0;
        while ($offset < $size) {
            if ($offset + 8 > $size) {
                $invalid();
            }
            $chunk = unpack('Vlength/Vtype', substr($bytes, $offset, 8));
            $offset += 8;
            if ($chunk['length'] % 4 || $offset + $chunk['length'] > $size) {
                $invalid();
            }
            if ($document === null) {
                if ($chunk['type'] !== 0x4E4F534A) {
                    $invalid();
                }
                $document = json_decode(substr($bytes, $offset, $chunk['length']), true, 64);
                if (! is_array($document)) {
                    $invalid();
                }
            } elseif ($chunk['type'] === 0x004E4942 && ! $binary) {
                $binary = $chunk['length'];
            } else {
                $invalid();
            }
            $offset += $chunk['length'];
        }
        if (($document['asset']['version'] ?? '') !== '2.0' || ! $binary || count($document['buffers'] ?? []) !== 1 || isset($document['buffers'][0]['uri']) || ($document['buffers'][0]['byteLength'] ?? PHP_INT_MAX) > $binary) {
            $invalid();
        }
        foreach ($document['images'] ?? [] as $image) {
            if (isset($image['uri']) && ! str_starts_with($image['uri'], 'data:')) {
                $invalid();
            }
        }
        foreach ($document['bufferViews'] ?? [] as $view) {
            if (($view['buffer'] ?? 0) !== 0 || ($view['byteOffset'] ?? 0) < 0 || ($view['byteLength'] ?? -1) < 0 || ($view['byteOffset'] ?? 0) + ($view['byteLength'] ?? 0) > $binary) {
                $invalid();
            }
        }
        $geometry = false;
        foreach ($document['meshes'] ?? [] as $mesh) {
            foreach ($mesh['primitives'] ?? [] as $primitive) {
                $position = $document['accessors'][$primitive['attributes']['POSITION'] ?? -1] ?? [];
                $geometry = $geometry || ($position['count'] ?? 0) >= 3 && ($position['type'] ?? '') === 'VEC3';
            }
        }
        if (! $geometry) {
            $invalid();
        }
    }
}
