<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\DB;

class Registry
{
    public const CLASSES = ['planning' => 'Planning', 'discovery' => 'Discovery', 'literature' => 'Literature', 'verification' => 'Verification', 'investigation' => 'Investigation', 'gaps' => 'Gaps', 'experiments' => 'Experiments', 'editor' => 'Editor', 'manuscript' => 'Manuscript', 'citations' => 'Citations', 'quality' => 'Quality', 'collaboration' => 'Collaboration', 'integrity' => 'Integrity', 'question' => 'QuestionAnswer', 'selection' => 'SelectionEditor'];

    public function get(string $key): Agent
    {
        abort_unless(isset(self::CLASSES[$key]), 422, 'Unknown research component.');
        $class = __NAMESPACE__.'\\Agents\\'.self::CLASSES[$key];

        return app($class);
    }

    public function snapshot(string $key): int
    {
        $a = $this->get($key);
        $definition = $a->definition();
        $file = (new \ReflectionClass($a))->getFileName();
        $tools = glob(__DIR__.'/*.php');
        sort($tools);
        $toolVersion = implode('', array_map(fn ($path) => hash_file('sha256', $path), $tools));
        $version = hash('sha256', file_get_contents($file).file_get_contents(__DIR__.'/Agents/BaseAgent.php').$toolVersion.json_encode($definition));
        $row = DB::table('research_agent_definitions')->where('key', $key)->where('version', $version)->first();
        if ($row) {
            return $row->id;
        }

        return DB::table('research_agent_definitions')->insertGetId(['key' => $key, 'version' => $version, 'definition' => json_encode($definition), 'created_at' => now(), 'updated_at' => now()]);
    }
}
