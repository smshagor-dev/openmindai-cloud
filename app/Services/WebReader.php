<?php

namespace App\Services;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class WebReader
{
    public array $lastRead = [];

    public string $query = '';

    // URLs are from untrusted search results; never allow private networks or DNS rebinding.
    public function read(string $url): string
    {
        $this->lastRead = [];
        for ($hop = 0; $hop < 4; $hop++) {
            $p = parse_url($url);
            if (! $p || ! in_array($p['scheme'] ?? '', ['https', 'http'], true) || isset($p['user']) || isset($p['pass']) || ! isset($p['host']) || isset($p['port']) && ! in_array($p['port'], [80, 443], true)) {
                throw new \RuntimeException('Unsupported URL.');
            }
            $host = $p['host'];
            if (! preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
                throw new \RuntimeException('Invalid host.');
            }
            $records = filter_var($host, FILTER_VALIDATE_IP) ? [] : (@dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
            $ips = [];
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips[] = $host;
            }
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if (! $ips) {
                throw new \RuntimeException('Host not found.');
            }
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $number = ip2long($ip);
                    if (($number & 0xFFC00000) === 0x64400000 || ($number & 0xFFFE0000) === 0xC6120000 || $number >= 0xE0000000 || preg_match('/^(192\.0\.2\.|198\.51\.100\.|203\.0\.113\.)/', $ip)) {
                        throw new \RuntimeException('Non-public addresses are forbidden.');
                    }
                } elseif (preg_match('/^2001:(db8|2|10|20):/i', $ip)) {
                    throw new \RuntimeException('Reserved IPv6 address.');
                }

                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_contains($ip, ':') && ! str_starts_with(strtolower($ip), '2') && ! str_starts_with(strtolower($ip), '3')) {
                    throw new \RuntimeException('Private addresses are forbidden.');
                }
            }
            if (! defined('CURLOPT_RESOLVE')) {
                throw new \RuntimeException('cURL is required for pinned DNS.');
            }
            $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
            $ip = $ips[0];
            $response = $this->fetchPinned($url, $host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip));
            $body = $response->toPsrResponse()->getBody();
            try {
                // Resolve redirects manually so every destination is validated before fetching.
                if (in_array($response->status(), [301, 302, 303, 307, 308])) {
                    $location = $response->header('Location');
                    if (! $location) {
                        throw new \RuntimeException('Redirect has no destination.');
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue; // Every hop is parsed, public-DNS validated and pinned again.
                }
                if (! $response->successful()) {
                    throw new \RuntimeException('Page unavailable or redirected.');
                }
                $mime = strtolower(explode(';', $response->header('Content-Type'))[0]);
                if (! in_array($mime, ['text/html', 'text/plain', 'application/pdf'], true)) {
                    throw new \RuntimeException('Unsupported page type.');
                }
                $html = '';
                while (! $body->eof()) {
                    $html .= $body->read(65536);
                    if (strlen($html) > 2000000) {
                        throw new \RuntimeException('Page too large.');
                    }
                }
            } finally {
                $body->close();
            }
            $this->lastRead = ['mime' => $mime, 'url' => $url];
            if ($mime === 'application/pdf') {
                $process = new Process([(string) config('web_research.python_binary', 'python3'), '-I', '-B', base_path('runtime/web-reader/pdf.py')], base_path('runtime/web-reader'));
                $clean = array_fill_keys(array_unique(array_merge(array_keys(getenv() ?: []), array_keys($_ENV))), false);
                $clean['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
                $process->setEnv($clean)->setInput(json_encode(['bytes' => base64_encode($html), 'query' => $this->query]))->setTimeout(12)->run();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('PDF extraction unavailable.');
                }
                $document = json_decode($process->getOutput(), true);
                if (! is_array($document['pages'] ?? null)) {
                    throw new \RuntimeException('Invalid PDF extraction result.');
                }
                $this->lastRead += ['pages' => $document['pages'], 'pdf' => $document['pdf']];

                return implode("\n", array_column($document['pages'], 'text'));
            }
            if ($mime === 'text/plain') {
                return mb_substr($html, 0, 128000);
            }
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $this->lastRead['title'] = mb_substr(trim($dom->getElementsByTagName('title')->item(0)?->textContent ?? ''), 0, 200);
            foreach (['script', 'style', 'nav', 'header', 'footer', 'noscript', 'iframe', 'form'] as $tag) {
                foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }

            return mb_substr(preg_replace('/\s+/u', ' ', $dom->textContent) ?? '', 0, 128000);
        }
        throw new \RuntimeException('Page unavailable.');
    }

    protected function fetchPinned(string $url, string $resolve): Response
    {
        return Http::connectTimeout(5)->timeout(6)->withHeaders(['User-Agent' => 'Openmindai-Research/1.0', 'Accept' => 'text/html,text/plain,application/pdf'])->withOptions(['handler' => new CurlHandler, 'progress' => function ($total, $downloaded) {
            if ($downloaded > 2000000) {
                throw new \RuntimeException('Page too large.');
            }
        }, 'proxy' => '', 'allow_redirects' => false, 'stream' => true, 'read_timeout' => 5, 'curl' => [CURLOPT_RESOLVE => [$resolve]]])->get($url);
    }
}
