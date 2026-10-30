<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Validator;

class Workflow
{
    public const PORTS = [
        'prompt' => [null, 'text'], 'image' => [null, 'image'], 'mesh' => [null, 'mesh'], 'scene' => [null, 'scene'],
        'preprocess' => ['image', 'image'], 'generate' => ['image', 'mesh'], 'generate_scene' => ['text', 'scene'],
        'process' => ['mesh', 'mesh'], 'preview' => ['geometry', 'geometry'], 'add_to_scene' => ['geometry', 'scene'], 'export' => ['geometry', 'artifact'],
    ];

    public function validate(array $graph): array
    {
        abort_if(strlen(json_encode($graph)) > 100000, 422, 'Workflow graph is too large.');
        $graph = Validator::make($graph, [
            'nodes' => 'required|array|list|min:1|max:40', 'nodes.*' => 'required|array:id,type,params',
            'nodes.*.id' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,40}$/|distinct',
            'nodes.*.type' => 'required|in:'.implode(',', array_keys(self::PORTS)), 'nodes.*.params' => 'present|array',
            'edges' => 'present|array|list|max:80', 'edges.*' => 'required|array:from,to', 'edges.*.from' => 'required|string', 'edges.*.to' => 'required|string',
        ])->validate();
        $nodes = array_column($graph['nodes'], null, 'id');
        $indegree = array_fill_keys(array_keys($nodes), 0);
        $outgoing = [];
        foreach ($graph['nodes'] as $node) {
            abort_if(strlen(json_encode($node['params'])) > 16000, 422, 'Workflow node parameters are too large.');
            $this->parameters($node['type'], $node['params']);
        }
        foreach ($graph['edges'] as $edge) {
            abort_unless(isset($nodes[$edge['from']], $nodes[$edge['to']]) && $edge['from'] !== $edge['to'], 422, 'Workflow connection references an invalid node.');
            [$input] = self::PORTS[$nodes[$edge['to']]['type']];
            [, $output] = self::PORTS[$nodes[$edge['from']]['type']];
            abort_unless($input !== null && ($input === $output || ($input === 'geometry' && in_array($output, ['mesh', 'scene', 'geometry'], true))), 422, 'Incompatible workflow connection types.');
            abort_if(++$indegree[$edge['to']] > 1, 422, 'Each input accepts one connection. Use explicit scene composition.');
            $outgoing[$edge['from']][] = $edge['to'];
        }
        $ready = array_keys(array_filter($indegree, fn ($n) => $n === 0));
        $order = [];
        while ($ready) {
            $id = array_shift($ready);
            $order[] = $id;
            foreach ($outgoing[$id] ?? [] as $target) {
                if (--$indegree[$target] === 0) {
                    $ready[] = $target;
                }
            }
        }
        abort_unless(count($order) === count($nodes), 422, 'Workflow cycles are not allowed.');
        foreach ($nodes as $node) {
            if (self::PORTS[$node['type']][0] !== null) {
                abort_unless(count(array_filter($graph['edges'], fn ($e) => $e['to'] === $node['id'])) === 1, 422, 'Connect every required workflow input.');
            }
        }

        return [...$graph, 'order' => $order];
    }

    private function parameters(string $type, array $params): void
    {
        $rules = match ($type) {
            'prompt' => ['text' => 'required|string|max:8000'],
            'image' => ['upload_id' => 'required|integer|min:1'],
            'mesh', 'scene' => ['asset_id' => 'required|integer|min:1'],
            'generate' => ['engine' => 'required|string|max:60', 'options' => 'sometimes|array'],
            'generate_scene' => ['mode' => 'required|in:object,architecture', 'upload_ids' => 'sometimes|array|max:5', 'upload_ids.*' => 'integer|distinct|min:1'],
            'preprocess' => ['size' => 'sometimes|integer|min:64|max:1024'],
            'process' => ['operation' => 'required|in:smooth,decimate,repair,transform', 'options' => 'sometimes|array'],
            'add_to_scene' => ['asset_ids' => 'sometimes|array|max:9', 'asset_ids.*' => 'integer|distinct|min:1'],
            'export' => ['format' => 'required|in:glb,gltf,obj,stl,ply,json'],
            default => [],
        };
        abort_if(array_diff(array_keys($params), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'))), 422, 'Unsupported workflow parameter.');
        Validator::make($params, $rules)->validate();
    }
}
