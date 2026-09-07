<?php

namespace App\Services\Studio\Providers;

/** Validates server-returned transfer URLs and pins the verified public IP for the connection. */
class FotelloTransferUrl
{
    public function __construct(private array $allowedHosts = []) {}

    public function validate(#[\SensitiveParameter] string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $allowed = array_map(fn ($value) => strtolower(trim((string) $value)), $this->allowedHosts);
        if (! is_array($parts) || strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($allowed !== [] && ! in_array($host, $allowed, true)) || filter_var($host, FILTER_VALIDATE_IP)
            || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            throw new FotelloException('unsafe_url');
        }

        return $host;
    }

    public function requestOptions(#[\SensitiveParameter] string $url): array
    {
        $host = $this->validate($url);
        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new FotelloException('unavailable', retryable: true);
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new FotelloException('unsafe_url');
            }
        }
        if (! defined('CURLOPT_RESOLVE')) {
            throw new FotelloException('configuration');
        }
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return [
            'allow_redirects' => false,
            'verify' => true,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],
        ];
    }

    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    }

    private function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // PHP's flags do not cover every special-use range or IPv4 embedded in IPv6.
        if (str_contains($ip, ':')) {
            $binary = inet_pton($ip);

            return $binary !== false && (ord($binary[0]) & 0xE0) === 0x20
                && substr($binary, 0, 2) !== hex2bin('2002')
                && substr($binary, 0, 2) !== hex2bin('3fff')
                && ! (substr($binary, 0, 2) === hex2bin('2001') && ord($binary[2]) < 2)
                && substr($binary, 0, 4) !== hex2bin('20010db8');
        }
        $first = array_map('intval', explode('.', $ip));

        return ! ($first[0] >= 224 || $first[0] === 0
            || ($first[0] === 100 && $first[1] >= 64 && $first[1] <= 127)
            || ($first[0] === 169 && $first[1] === 254)
            || ($first[0] === 192 && $first[1] === 0)
            || ($first[0] === 198 && in_array($first[1], [18, 19, 51], true))
            || ($first[0] === 203 && $first[1] === 0 && $first[2] === 113));
    }
}
