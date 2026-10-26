<?php

namespace App\Services;

class WebEvidence
{
    public function passages(string $text, string $query, array $document = []): array
    {
        $terms = $this->terms($query);
        $pages = ($document['mime'] ?? '') === 'application/pdf' ? ($document['pages'] ?? []) : [['number' => null, 'text' => $text]];
        $ranked = [];
        foreach ($pages as $page) {
            $clauses = preg_split('/(?<=[!?।\n])\s*|(?<!\d)\.\s+|(?<=\d)\.(?!\d)\s+/u', (string) $page['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($clauses as $order => $clause) {
                if (mb_strlen(trim($clause)) < 25) {
                    continue;
                }
                $words = $this->terms($clause);
                $score = count(array_intersect($terms, $words));
                if (mb_strlen($clause) > 900) {
                    $start = 0;
                    foreach ($terms as $term) {
                        $at = mb_stripos($clause, $term);
                        if ($at !== false) {
                            $start = max(0, $at - 100);
                            break;
                        }
                    }
                    $clause = mb_substr($clause, $start, 900);
                }
                $ranked[] = ['text' => trim($clause), 'page' => $page['number'], 'score' => $score, 'order' => count($ranked)];
            }
        }
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order']);
        $selected = array_slice($ranked, 0, 8);
        usort($selected, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(fn ($p, $i) => ['id' => 'p'.($i + 1), 'text' => $p['text'], 'page' => $p['page']], $selected, array_keys($selected));
    }

    public function read(string $url, string $query = ''): array
    {
        $reader = app(WebReader::class);
        $reader->query = $query;
        $text = $reader->read($url);
        $document = $reader->lastRead;
        $passages = $this->passages($text, $query, $document);
        if (! $passages && mb_strlen(trim($text)) >= 80) {
            $passages = [['id' => 'p1', 'text' => mb_substr(trim($text), 0, 1500), 'page' => null]];
        }
        $selected = implode("\n\n", array_map(fn ($p) => ($p['page'] ? 'PDF page '.$p['page'].': ' : '').$p['text'], $passages));

        return ['url' => $url, 'page_text' => $selected, 'read_status' => mb_strlen(trim($text)) >= 80 ? 'read' : 'unavailable', 'passages' => $passages, 'mime' => $document['mime'] ?? 'text/plain', 'title' => $document['title'] ?? null, 'final_url' => $document['url'] ?? $url, 'retrieved_at' => now()->toIso8601String(), 'pdf' => $document['pdf'] ?? null];
    }

    public function summary(array $source): ?array
    {
        $url = WebSearch::canonicalUrl($source['url'] ?? '');
        if (! $url) {
            return null;
        }
        $result = ['url' => $url, 'title' => mb_substr((string) ($source['title'] ?? $url), 0, 200), 'snippet' => mb_substr((string) ($source['snippet'] ?? ''), 0, 2000), 'read_status' => in_array($source['read_status'] ?? '', ['read', 'snippet_only', 'unavailable']) ? $source['read_status'] : 'snippet_only', 'citation' => max(1, (int) ($source['citation'] ?? 1))];
        foreach (['provider', 'retrieved_at', 'mime', 'final_url', 'duplicate_of'] as $key) {
            if (is_string($source[$key] ?? null)) {
                $result[$key] = mb_substr($source[$key], 0, $key === 'provider' ? 40 : 2000);
            }
        }
        $result['passages'] = [];
        foreach (array_slice(is_array($source['passages'] ?? null) ? $source['passages'] : [], 0, 8) as $i => $passage) {
            if (! is_array($passage) || ! is_string($passage['text'] ?? null)) {
                continue;
            }
            $result['passages'][] = ['id' => 'p'.($i + 1), 'text' => mb_substr($passage['text'], 0, 1000), 'page' => ($source['mime'] ?? '') === 'application/pdf' && is_int($passage['page'] ?? null) && $passage['page'] > 0 && $passage['page'] <= 5000 ? $passage['page'] : null];
        }
        if (($result['mime'] ?? '') === 'application/pdf' && is_array($source['pdf'] ?? null)) {
            $pdf = $source['pdf'];
            $total = max(1, min(5000, (int) ($pdf['total_pages'] ?? 1)));
            $result['pdf'] = ['total_pages' => $total, 'sampled_pages' => array_values(array_filter(array_slice($pdf['sampled_pages'] ?? [], 0, 8), fn ($n) => is_int($n) && $n > 0 && $n <= $total)), 'partial' => (bool) ($pdf['partial'] ?? true), 'ocr' => false];
        }

        return $result;
    }

    public function ground(string $answer, array $sources): array
    {
        $known = [];
        foreach ($sources as $i => $source) {
            $url = WebSearch::canonicalUrl($source['url'] ?? '');
            if ($url) {
                $known[$source['citation'] ?? $i + 1] = $url;
            }
        }
        $removed = 0;
        $parts = preg_split('/(```[\s\S]*?```|`[^`\n]+`)/', $answer, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2) {
                continue;
            }
            $part = preg_replace_callback('~\[([^\]\n]+)\]\((https?://(?:[^\s()]|\([^\s()]*\))+)\)~', function ($match) use ($known, &$removed) {
                $url = WebSearch::canonicalUrl($match[2]);
                $number = ctype_digit($match[1]) ? (int) $match[1] : array_search($url, $known, true);
                if ($number !== false && $url && ($known[$number] ?? null) === $url) {
                    return '['.$number.']('.str_replace(['(', ')'], ['%28', '%29'], $url).')';
                }
                $removed++;

                return '[unverified citation omitted]';
            }, $part);
            $part = preg_replace_callback('/\[(\d+)\](?!\()/u', function ($match) use ($known, &$removed) {
                if (isset($known[(int) $match[1]])) {
                    return '['.$match[1].']('.str_replace(['(', ')'], ['%28', '%29'], $known[(int) $match[1]]).')';
                }
                $removed++;

                return '[unverified citation omitted]';
            }, $part);
        }
        unset($part);
        $text = implode('', $parts);
        $footer = "\n\nSources\n\n";
        foreach ($sources as $i => $source) {
            $n = $source['citation'] ?? $i + 1;
            if (! isset($known[$n])) {
                continue;
            }$title = str_replace(['[', ']', '\\', "\r", "\n"], ' ', (string) ($source['title'] ?? 'Source'));
            $footer .= $n.'. ['.$title.']('.$known[$n].') · '.(($source['read_status'] ?? '') === 'read' ? 'page read' : 'search snippet only')."\n";
        }
        $footer .= "\nPage retrieval and citation URL checks do not establish that every claim is supported.";

        return ['text' => $text.$footer, 'removed' => $removed];
    }

    private function terms(string $text): array
    {
        preg_match_all('/[\pL\pM\pN_]{3,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0]));
    }
}
