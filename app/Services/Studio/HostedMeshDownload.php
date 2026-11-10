<?php

namespace App\Services\Studio;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

class HostedMeshDownload
{
    public function get(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! $this->allowed($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new UnexpectedValueException('Unsupported provider artifact URL.');
        }
        $host = $parts['host'];
        $ips = $this->addresses($host);
        if (! $ips || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Artifact transport unavailable.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|198\.(18|19)\.)/', $ip) || (str_contains($ip, ':') && ! preg_match('/^[23]/', $ip))) {
                throw new UnexpectedValueException('Non-public artifact destination.');
            }
        }
        $max = (int) config('studio.image_model.max_bytes', 25000000);
        // Never forward FAL_KEY, cookies or provider authentication to the CDN.
        $response = Http::connectTimeout(4)->timeout(12)->setHandler(new CurlHandler)->withOptions(['proxy' => '', 'allow_redirects' => false, 'stream' => true, 'read_timeout' => 5, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0])]], 'progress' => function ($total, $downloaded) use ($max) {
            if ($downloaded > $max) {
                throw new UnexpectedValueException('Mesh too large.');
            }
        }])->get($url);
        $body = $response->toPsrResponse()->getBody();
        try {
            if (! $response->successful()) {
                throw new RuntimeException('Artifact download unavailable.');
            }
            $bytes = '';
            while (! $body->eof()) {
                $bytes .= $body->read(65536);
                if (strlen($bytes) > $max) {
                    throw new UnexpectedValueException('Mesh too large.');
                }
            }

            return $bytes;
        } finally {
            $body->close();
        }
    }

    protected function allowed(array $parts): bool
    {
        return (bool) preg_match('/^(?:[a-z0-9-]+\.)*fal\.media$/iD', $parts['host'] ?? '');
    }

    protected function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
