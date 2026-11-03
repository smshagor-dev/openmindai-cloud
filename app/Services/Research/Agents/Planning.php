<?php

namespace App\Services\Research\Agents;

use App\Services\Research\Context;

class Planning extends BaseAgent
{
    protected array $tools = ['structured_reasoning'];

    protected array $rules = ['language' => 'required|string|max:50', 'output_language' => 'required|string|max:50', 'discipline' => 'required|string|max:200', 'objectives' => 'required|array|min:1|max:8', 'constraints' => 'present|array', 'perspectives' => 'required|array|min:1|max:4', 'perspectives.*.name' => 'required|string|max:150', 'perspectives.*.query' => 'required|string|max:300', 'experimental' => 'required|boolean'];

    public function execute(Context $ctx): array
    {
        $out = $this->ask($ctx, 'Interpret the original multilingual/mixed/transliterated prompt including Banglish. Detect progress language without changing user intent; preserve explicitly chosen manuscript language. Use objectives to state exactly what the user wants answered and delivered. Capture requested approach, audience, scope, format, journal, date range, datasets, methods and exclusions in constraints. Distinguish explicit requirements from assumptions and flag materially ambiguous requests in constraints. Do not silently change the topic or method. Extract discipline, objectives, constraints, 1–4 complementary investigation perspectives with real scholarly search queries, and experimental boolean. Return language, output_language, discipline, objectives[], constraints{}, perspectives[{name,query}], experimental. Do not search or invent results.', $this->project($ctx));
        foreach (['progress_language' => 'language', 'output_language' => 'output_language'] as $setting => $key) {
            $chosen = $ctx->project()->settings[$setting] ?? 'auto';
            if (strtolower($chosen) !== 'auto') {
                $out[$key] = $chosen;
            }
        }

        return $out;
    }
}
