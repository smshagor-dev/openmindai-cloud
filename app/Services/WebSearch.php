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
        // Optional "country-language" region (e.g. ru-ru) so local-language sites
        // are searched for country-specific questions.
        [$country, $language] = preg_match('/^([a-z]{2})-([a-z]{2})$/', (string) ($options['region'] ?? ''), $region) ? [$region[1], $region[2]] : [null, null];
        $providers = [];
        if (config('models.search_url')) {
            $providers[] = ['searxng', config('models.search_url'), [...$params, ...($language ? ['language' => $language.'-'.strtoupper($country)] : [])]];
        }
        if (config('web_research.brave_key')) {
            $providers[] = ['brave', 'https://api.search.brave.com/res/v1/web/search', ['q' => $params['q'], 'count' => 10, ...($country ? ['country' => $country, 'search_lang' => $language] : [])]];
        }
        if (config('web_research.google_key') && config('web_research.google_cx')) {
            $providers[] = ['google', 'https://www.googleapis.com/customsearch/v1', ['q' => $params['q'], 'key' => config('web_research.google_key'), 'cx' => config('web_research.google_cx'), 'num' => 10, ...($country ? ['gl' => $country, 'hl' => $language] : [])]];
        }
        // Official Yandex Search API (XML); anonymous yandex.ru searches are captcha-gated.
        if (config('web_research.yandex_key') && config('web_research.yandex_folder')) {
            $domain = $country === 'tr' ? 'com.tr' : (in_array($country, ['ru', 'by', 'kz', 'uz'], true) ? 'ru' : 'com');
            $providers[] = ['yandex', 'https://yandex.'.$domain.'/search/xml', ['folderid' => config('web_research.yandex_folder'), 'apikey' => config('web_research.yandex_key'), 'query' => $params['q'], 'l10n' => $language ?: 'en', 'sortby' => 'rm', 'filter' => 'moderate', 'groupby' => 'attr=d.mode=deep.groups-on-page=10.docs-in-group=1']];
        }
        if (config('models.public_search_fallback')) {
            $providers[] = ['bing_rss', 'https://www.bing.com/search', ['q' => $params['q'], 'format' => 'rss', ...($country ? ['cc' => strtoupper($country), 'setlang' => $language] : [])]];
            $providers[] = ['duckduckgo', 'https://html.duckduckgo.com/html/', ['q' => $params['q'], ...($country ? ['kl' => $country.'-'.$language] : [])]];
        }
        // Every available engine is queried and results are merged, so a site
        // missing from one engine's index can still be found through another.
        $events = [];
        $merged = [];
        $deadline = microtime(true) + 20;
        foreach ($providers as $index => [$provider, $url, $queryParams]) {
            $cooldown = 'web-search:cooldown:'.hash('sha256', $provider.$url.(string) config('web_research.brave_key').(string) config('web_research.google_key').(string) config('web_research.yandex_key'));
            if (Cache::has($cooldown)) {
                $events[] = ['provider' => $provider, 'status' => 'cooldown'];

                continue;
            }
            if (microtime(true) > $deadline) {
                $events[] = ['provider' => $provider, 'status' => 'skipped_time_budget'];

                continue;
            }
            $providerDeadline = microtime(true) + ($deadline - microtime(true)) / (count($providers) - $index);
            for ($attempt = 1; $attempt <= config('web_research.attempts', 2); $attempt++) {
                $remaining = $providerDeadline - microtime(true);
                if ($remaining < 0.25) {
                    break;
                }
                try {
                    $r = Http::withHeaders(['Accept' => in_array($provider, ['searxng', 'brave', 'google']) ? 'application/json' : 'application/rss+xml,text/html', 'User-Agent' => 'OpenMindAI-Research/2.0', ...($provider === 'brave' ? ['X-Subscription-Token' => config('web_research.brave_key')] : [])])->connectTimeout(min(2, $remaining))->timeout(min(6, $remaining))->withOptions(['allow_redirects' => false, 'stream' => true])->get($url, $queryParams);
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
                            if (strlen($body) > 1000000 || microtime(true) > $providerDeadline) {
                                throw new \RuntimeException('Search response exceeded its size or time budget.');
                            }
                        }
                    } finally {
                        $stream->close();
                    }
                    $results = $this->normalize($this->parseResults($provider, $body), $query);
                    $events[] = ['provider' => $provider, 'status' => $results ? 'completed' : 'empty', 'attempt' => $attempt];
                    foreach ($results as $result) {
                        if (isset($merged[$result['url']])) {
                            $merged[$result['url']]['providers'][] = $provider;
                            $merged[$result['url']]['snippet'] = $merged[$result['url']]['snippet'] ?: $result['snippet'];
                        } else {
                            $merged[$result['url']] = [...$result, 'provider' => $provider, 'providers' => [$provider]];
                        }
                    }
                    break;
                } catch (\Throwable $e) {
                    $events[] = ['provider' => $provider, 'status' => 'unavailable', 'attempt' => $attempt];
                    if ($attempt >= config('web_research.attempts', 2)) {
                        Cache::put($cooldown, true, config('web_research.cooldown_seconds', 30));
                    }
                }
            }
        }
        if (! $merged) {
            abort(503, 'Web search is unavailable. No live sources could be retrieved.');
        }
        // Rank: matched query terms, agreement between engines, and the requested
        // year (a "2026" question must not be answered from 2024 pages first).
        preg_match_all('/\b(?:19|20)\d{2}\b/', $query, $years);
        $rank = fn (array $result) => $result['score'] + 2 * (count($result['providers']) - 1)
            + ($years[0] && array_filter($years[0], fn ($year) => preg_match('/(?<!\d)'.$year.'(?!\d)/', urldecode($result['url']).' '.$result['title'].' '.$result['snippet'])) ? 3 : 0);
        $results = array_values($merged);
        usort($results, fn ($a, $b) => $rank($b) <=> $rank($a));

        return array_map(fn ($source) => [...array_diff_key($source, ['score' => true]), 'retrieved_at' => now()->toIso8601String(), 'provider_events' => $events, 'time_filter_applied' => in_array('searxng', $source['providers'], true) && isset($params['time_range'])],
            array_slice($results, 0, min(10, max(1, (int) ($options['limit'] ?? 5)))));
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
            $loaded = in_array($provider, ['bing_rss', 'yandex'], true)
                ? $dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                : $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                return [];
            }
            $rows = [];
            if ($provider === 'yandex') {
                // <doc><url/><title/><passages><passage/></passages></doc>; an
                // <error> response simply has no documents.
                foreach ($dom->getElementsByTagName('doc') as $doc) {
                    $passages = array_map(fn ($node) => $node->textContent, iterator_to_array($doc->getElementsByTagName('passage')));
                    $rows[] = ['url' => $doc->getElementsByTagName('url')->item(0)?->textContent ?? '', 'title' => $doc->getElementsByTagName('title')->item(0)?->textContent ?? '', 'content' => implode(' … ', $passages) ?: ($doc->getElementsByTagName('headline')->item(0)?->textContent ?? '')];
                }
            } elseif ($provider === 'bing_rss') {
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

    private function normalize(array $rows, string $query): array
    {
        // Preserve technical identifiers such as array_map: a generic PHP home
        // page is not relevant evidence for a requested function.
        preg_match_all('/(?<![\pL\pN_])[\pL][\pL\pN]*_[\pL\pN_]+/u', $query, $matches);
        $identifiers = array_map(fn ($term) => str_replace('_', '-', mb_strtolower($term)), $matches[0]);
        // Numbers such as model years are topics too: "Pro 2026" is not "Pro 2024".
        preg_match_all('/[\pL\pN][\pL\pM\pN_]{2,}/u', $query, $words);
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
            // One shared word is not enough: Bing RSS answers specific product
            // queries with brand home pages that only match the brand name.
            $score = count(array_filter($topics, fn ($term) => str_contains($evidence, str_replace('_', '-', $term))));
            if (count($topics) >= 2 && $score < max(2, (int) ceil(count($topics) / 3))) {
                continue;
            }
            $results[$url] = ['title' => $title, 'url' => $url, 'snippet' => $snippet, 'score' => $score];
            if (count($results) >= 10) {
                break;
            }
        }

        // Scores are kept for ranking across engines; search() removes them.
        return array_values($results);
    }
}
