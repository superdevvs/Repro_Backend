<?php

namespace App\Services\IguideLab;

use Illuminate\Validation\ValidationException;

/** The observed provider route only. No embedded URLs, redirects, cookies or credentials. */
class LabImportNetwork
{
    public function tour(string $url): array
    {
        // parse_url replaces raw control characters; do not silently change a token.
        $this->require(! preg_match('/[\x00-\x1f\x7f]/', $url), 'This tour link contains malformed characters.');
        $parts = parse_url($url);
        $this->require(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'youriguide.com'
            && ! isset($parts['user'], $parts['pass']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port']) && ! isset($parts['fragment']), 'Use a supported HTTPS youriguide.com tour link.');
        $this->require((bool) preg_match('~^/(?:embed/)?([a-zA-Z0-9_-]{1,200})/?$~D', $parts['path'] ?? '', $match), 'The link must identify one iGUIDE tour, not an arbitrary asset.');
        $query = $this->tourQuery($parts['query'] ?? '');
        $alias = $match[1]; $base = 'https://youriguide.com/'.$alias.'/';
        $suffix = $query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        return ['alias' => $alias, 'publicUrl' => $base, 'pageUrl' => $base.$suffix, 'archiveUrl' => $base.'doc/spheres.zip'.$suffix];
    }

    private function tourQuery(string $raw): array
    {
        // parse_str silently collapses duplicates and interprets array syntax.
        // Validate decoded names before canonicalizing a whole-tour identity.
        $this->require(strlen($raw) <= 16384, 'This tour link contains unsupported parameters.');
        $query = [];
        foreach ($raw === '' ? [] : explode('&', $raw) as $field) {
            $this->require(str_contains($field, '=') && ! preg_match('/%(?![a-fA-F0-9]{2})/', $field), 'This tour link contains malformed parameters.');
            [$name, $value] = array_map('urldecode', explode('=', $field, 2));
            $this->require(in_array($name, ['accessToken', 'pano'], true) && ! array_key_exists($name, $query), 'This tour link contains unsupported or repeated parameters.');
            if ($name === 'pano') {
                $this->require((bool) preg_match('/\A[0-9]{1,10}\z/D', $value) && (float) $value <= 2147483647, 'The viewer panorama ID must be a bounded nonnegative integer.');
            } else {
                $this->require(strlen($value) <= 4096 && ! preg_match('/[\x00-\x1f\x7f]/', $value), 'This tour link contains an invalid access token.');
            }
            $query[$name] = $value;
        }
        // pano changes only the copied viewer position, never download authority
        // or the complete-tour page/archive/import request identity.
        return array_key_exists('accessToken', $query) ? ['accessToken' => $query['accessToken']] : [];
    }

    public function download(string $url, string $target, int $limit): array
    {
        $parts = parse_url($url); $host = $parts['host'] ?? '';
        $this->require($host === 'youriguide.com' && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && (bool) preg_match('~^/[a-zA-Z0-9_-]{1,200}/(?:doc/spheres\.zip)?$~D', $parts['path'] ?? ''), 'Unsupported provider download route.');
        $ips = $this->addresses($host);
        $this->require(count($ips) > 0, 'The provider address could not be resolved.');
        foreach ($ips as $ip) $this->require($this->publicAddress($ip), 'The provider address is not a public network address.');
        $stream = fopen($target, 'xb'); $this->require(is_resource($stream), 'Private download staging is unavailable.');
        $curl = curl_init($url); $written = 0; $tooLarge = false;
        try {
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'RePro-Scan-Import/1',
                CURLOPT_RESOLVE => [$host.':443:'.(str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0])],
                CURLOPT_HEADERFUNCTION => function ($curl, string $line) use ($limit, &$tooLarge): int {
                    if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m) && (float) $m[1] > $limit) { $tooLarge = true; return 0; }
                    return strlen($line);
                }, CURLOPT_WRITEFUNCTION => function ($curl, string $bytes) use ($stream, $limit, &$written, &$tooLarge): int {
                    if ($written + strlen($bytes) > $limit) { $tooLarge = true; return 0; }
                    $count = fwrite($stream, $bytes); $written += $count ?: 0; return $count ?: 0;
                }]);
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $this->require(! $tooLarge, 'The provider download exceeds the import size limit.');
            $this->require($ok !== false && $status === 200 && $written > 0, 'The provider source is unavailable, protected, redirected, or incomplete. Upload the original sphere ZIP instead.');
        } finally { curl_close($curl); fclose($stream); }
        return ['sha256' => hash_file('sha256', $target), 'sizeBytes' => $written];
    }

    protected function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    }
    public function publicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        // Explicitly exclude transition/mapped, carrier NAT and multicast ranges too.
        $binary = inet_pton($ip);
        if (strlen($binary) === 4) {
            $first = ord($binary[0]); $second = ord($binary[1]);
            return $first < 224 && ! ($first === 100 && $second >= 64 && $second <= 127) && $first !== 0 && $first !== 127 && ! ($first === 169 && $second === 254);
        }
        return str_starts_with(bin2hex($binary), '2') && ! str_starts_with(bin2hex($binary), '2002') && ! str_starts_with(bin2hex($binary), '20010000');
    }
    private function require(bool $condition, string $message): void { if (! $condition) throw ValidationException::withMessages(['source' => $message]); }
}
