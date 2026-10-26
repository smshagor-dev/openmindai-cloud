<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WebSearch
{
    public static function canonicalUrl(string $url): ?string
    {
        $p = parse_url($url);
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && ! in_array($p['port'], [80, 443], true))) {
            return null;
        }
        $host = rtrim(strtolower($p['host']), '.');
        if (! preg_match('/^[a-z0-9.-]+$/', $host) || ! str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }
        if (preg_match('/^[0-9.]+$/', $host) && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_|fbclid$|gclid$)/i', $key)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return strtolower($p['scheme']).'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '/').($query ? '?'.http_build_query($query) : '');
    }

    public static function isSearchPortal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $portal = preg_match('/^(www\.|search\.|html\.)?(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.com|search\.yahoo\.com)$/D', $host);

        return (bool) $portal && ($path === '' || preg_match('~^/(search|xhtml/search|html|lite|web|results)(/|$)~', $path));
    }

    public function search(string $query, array $options = []): array
    {
        $params = ['q' => mb_substr($query, 0, 500), 'format' => 'json', 'safesearch' => 1];
        if (in_array($options['time_range'] ?? '', ['day', 'week', 'month', 'year'], true)) {
            $params['time_range'] = $options['time_range'];
        }
        $providers = [];
        if (config('models.search_url')) {
            $providers[] = ['searxng', config('models.search_url'), $params];
        }
        if (config('web_research.brave_key')) {
            $providers[] = ['brave', 'https://api.search.brave.com/res/v1/web/search', ['q' => $params['q'], 'count' => 10]];
        }
        if (config('web_research.google_key') && config('web_research.google_cx')) {
            $providers[] = ['google', 'https://www.googleapis.com/customsearch/v1', ['q' => $params['q'], 'key' => config('web_research.google_key'), 'cx' => config('web_research.google_cx'), 'num' => 10]];
        }
        if (config('models.public_search_fallback')) {
            $providers[] = ['bing_rss', 'https://www.bing.com/search', ['q' => $params['q'], 'format' => 'rss']];
            $providers[] = ['duckduckgo', 'https://html.duckduckgo.com/html/', ['q' => $params['q']]];
        }
        $events = [];
        foreach ($providers as [$provider, $url, $queryParams]) {
            $cooldown = 'web-search:cooldown:'.hash('sha256', $provider.$url.(string) config('web_research.brave_key').(string) config('web_research.google_key'));
            if (Cache::has($cooldown)) {
                $events[] = ['provider' => $provider, 'status' => 'cooldown'];

                continue;
            }
            for ($attempt = 1; $attempt <= config('web_research.attempts', 2); $attempt++) {
                try {
                    $r = Http::withHeaders(['Accept' => in_array($provider, ['searxng', 'brave', 'google']) ? 'application/json' : 'application/rss+xml,text/html', 'User-Agent' => 'OpenMindAI-Research/2.0', ...($provider === 'brave' ? ['X-Subscription-Token' => config('web_research.brave_key')] : [])])->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url, $queryParams);
                    if (! $r->successful()) {
                        $r->toPsrResponse()->getBody()->close();
                        $events[] = ['provider' => $provider, 'status' => 'failed', 'http_status' => $r->status(), 'attempt' => $attempt];
                        if (in_array($r->status(), [429, 500, 502, 503, 504]) && $attempt < config('web_research.attempts', 2) && $r->status() !== 429) {
                            if (! app()->runningUnitTests()) {
                                usleep(150000);
                            }

                            continue;
                        }
                        if (in_array($r->status(), [401, 403, 429, 500, 502, 503, 504])) {
                            Cache::put($cooldown, true, max(1, min(120, (int) ($r->header('Retry-After') ?: config('web_research.cooldown_seconds', 30)))));
                        }
                        break;
                    }
                    $stream = $r->toPsrResponse()->getBody();
                    try {
                        $body = '';
                        while (! $stream->eof()) {
                            $body .= $stream->read(65536);
                            if (strlen($body) > 1000000) {
                                throw new \RuntimeException('Search response too large.');
                            }
                        }
                    } finally {
                        $stream->close();
                    }
                    $rows = $this->parseResults($provider, $body);
                    $results = $this->normalize($rows, $options, $query);
                    if ($results) {
                        return array_map(fn ($source) => [...$source, 'provider' => $provider, 'retrieved_at' => now()->toIso8601String(), 'provider_events' => [...$events, ['provider' => $provider, 'status' => 'completed', 'attempt' => $attempt]], 'time_filter_applied' => $provider === 'searxng' && isset($params['time_range'])], $results);
                    }
                    $events[] = ['provider' => $provider, 'status' => 'empty', 'attempt' => $attempt];
                    break;
                } catch (\Throwable $e) {
                    $events[] = ['provider' => $provider, 'status' => 'unavailable', 'attempt' => $attempt];
                    if ($attempt >= config('web_research.attempts', 2)) {
                        Cache::put($cooldown, true, config('web_research.cooldown_seconds', 30));
                    }
                }
            }
        }
        abort(503, 'Web search is unavailable. No live sources could be retrieved.');
    }

    private function parseResults(string $provider, string $body): array
    {
        if (in_array($provider, ['brave', 'google'])) {
            $data = json_decode($body, true);
            $items = $provider === 'brave' ? ($data['web']['results'] ?? []) : ($data['items'] ?? []);

            return array_map(fn ($item) => ['url' => $item[$provider === 'brave' ? 'url' : 'link'] ?? '', 'title' => $item['title'] ?? '', 'content' => $item[$provider === 'brave' ? 'description' : 'snippet'] ?? ''], is_array($items) ? $items : []);
        }
        if ($provider === 'searxng') {
            $data = json_decode($body, true);

            return is_array($data['results'] ?? null) ? $data['results'] : [];
        }
        if (stripos($body, '<!DOCTYPE') !== false && $provider === 'bing_rss') {
            return [];
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $provider === 'bing_rss'
                ? $dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                : $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                return [];
            }
            $rows = [];
            if ($provider === 'bing_rss') {
                foreach ($dom->getElementsByTagName('item') as $item) {
                    $rows[] = ['url' => $item->getElementsByTagName('link')->item(0)?->textContent ?? '', 'title' => $item->getElementsByTagName('title')->item(0)?->textContent ?? '', 'content' => $item->getElementsByTagName('description')->item(0)?->textContent ?? ''];
                }
            } else {
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]') as $link) {
                    $url = $link->getAttribute('href');
                    if (str_starts_with($url, '//')) {
                        $url = 'https:'.$url;
                    }
                    $parts = parse_url($url);
                    if (in_array($parts['host'] ?? '', ['duckduckgo.com', 'www.duckduckgo.com'], true)) {
                        parse_str($parts['query'] ?? '', $wrapped);
                        $url = is_string($wrapped['uddg'] ?? null) ? $wrapped['uddg'] : '';
                    }
                    $rows[] = ['url' => $url, 'title' => $link->textContent, 'content' => ''];
                }
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalize(array $rows, array $options, string $query): array
    {
        // Preserve technical identifiers such as array_map: a generic PHP home
        // page is not relevant evidence for a requested function.
        preg_match_all('/(?<![\pL\pN_])[\pL][\pL\pN]*_[\pL\pN_]+/u', $query, $matches);
        $identifiers = array_map(fn ($term) => str_replace('_', '-', mb_strtolower($term)), $matches[0]);
        preg_match_all('/[\pL][\pL\pM\pN_]{2,}/u', $query, $words);
        $stop = explode(' ', 'the and for from with that this what when where which how are was were does using use into versus official documentation document search web research read sources source report compare comparing explain write concise detailed include exact url urls page pages evidence limitations information please about findings only then its can not could would should have has their they them you your request');
        $topics = array_values(array_unique(array_diff(array_map('mb_strtolower', $words[0]), $stop)));
        preg_match('/\bsite:([a-z0-9.-]+)/i', $query, $site);
        $results = [];
        foreach ($rows as $v) {
            if (! is_array($v) || ! is_string($v['url'] ?? null) || strlen($v['url']) > 2000 || ! ($url = self::canonicalUrl($v['url']))) {
                continue;
            }
            if (self::isSearchPortal($url) || isset($results[$url])) {
                continue;
            }
            $text = fn ($value, $max) => mb_substr(html_entity_decode(strip_tags(is_scalar($value) ? (string) $value : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, $max);
            $title = $text($v['title'] ?? $url, 200) ?: mb_substr($url, 0, 200);
            $snippet = $text($v['content'] ?? '', 2000);
            $evidence = str_replace('_', '-', mb_strtolower(urldecode($url).' '.$title.' '.$snippet));
            if ($identifiers && ! array_filter($identifiers, fn ($term) => str_contains($evidence, $term))) {
                continue;
            }
            if (isset($site[1])) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $domain = strtolower($site[1]);
                if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
                    continue;
                }
            }
            // Reject provider fallback noise rather than treating unrelated pages
            // as research evidence merely because the HTTP request succeeded.
            if (count($topics) >= 2 && ! array_filter($topics, fn ($term) => str_contains($evidence, str_replace('_', '-', $term)))) {
                continue;
            }
            $results[$url] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
            if (count($results) >= min(10, max(1, (int) ($options['limit'] ?? 5)))) {
                break;
            }
        }

        return array_values($results);
    }
}
