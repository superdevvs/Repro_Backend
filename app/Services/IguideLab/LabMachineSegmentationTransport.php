<?php

namespace App\Services\IguideLab;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Server-owned SAM transport. No retries, logs, client URLs or implicit model routing. */
class LabMachineSegmentationTransport
{
    public const MODEL = 'fal-ai/sam-3-1/image';
    public const MAX_RESPONSE_BYTES = 1024 * 1024;
    public const MAX_MASK_BYTES = 8 * 1024 * 1024;

    public function ready(): bool { return is_string(config('services.fal.key')) && trim(config('services.fal.key')) !== '' && config('services.fal.key') !== 'PASTE_YOUR_FAL_KEY_HERE'; }

    /** Called only after the durable workflow paid claim commits. */
    public function submit(array $payload): array
    {
        abort_unless($this->ready(), 503, 'Segmentation is unavailable.');
        try {
            $response = $this->request()->post('https://queue.fal.run/'.self::MODEL, $payload);
            $raw = $this->bounded($response, self::MAX_RESPONSE_BYTES);
            $body = json_decode($raw, true); $id = is_array($body) ? ($body['request_id'] ?? null) : null;
            $received = $response->successful() && is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $id);
            $rejected = $response->status() >= 400 && $response->status() < 500 && ! in_array($response->status(), [408, 409, 425, 429], true);
            return ['status' => $received ? 'received' : ($rejected ? 'rejected' : 'unknown'), 'httpStatus' => $response->status(),
                'providerRequestId' => $received ? $id : null, 'raw' => $raw, 'responseSha256' => hash('sha256', $raw)];
        } catch (Throwable) {
            // Never propagate exceptions containing authorization, data URLs or payloads.
            return ['status' => 'unknown', 'httpStatus' => null, 'providerRequestId' => null, 'raw' => null, 'responseSha256' => null];
        }
    }

    /** GET-only recovery of a previously recorded provider receipt. */
    public function poll(string $id): ?array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $id), 422, 'Invalid segmentation receipt.');
        $base = 'https://queue.fal.run/fal-ai/sam-3-1/requests/'.$id;
        try {
            $status = $this->request()->get($base.'/status'); $statusBytes = $this->bounded($status, self::MAX_RESPONSE_BYTES);
            if (! $status->successful()) throw new RuntimeException('Segmentation status is unavailable.');
            $state = json_decode($statusBytes, true)['status'] ?? null;
            if (in_array($state, ['IN_QUEUE', 'IN_PROGRESS'], true)) return null;
            if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED', 'CANCELED', 'REJECTED'], true)) return ['status' => 'failed', 'raw' => $statusBytes];
            if ($state !== 'COMPLETED') throw new RuntimeException('Unknown segmentation state.');
            $response = $this->request()->get($base.'/response');
            if (in_array($response->status(), [404, 405], true)) { $response->toPsrResponse()->getBody()->close(); $response = $this->request()->get($base); }
            $raw = $this->bounded($response, self::MAX_RESPONSE_BYTES);
            if (! $response->successful()) throw new RuntimeException('Segmentation result is unavailable.');
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) throw new RuntimeException('Invalid segmentation result.');
            return ['status' => 'completed', 'raw' => $raw, 'result' => $decoded];
        } catch (Throwable) { throw new RuntimeException('The existing segmentation receipt could not be read. No new request was sent.'); }
    }

    public function mask(string $url): string
    {
        $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && strlen($url) <= 4096
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port']) && ! isset($parts['fragment'])
            && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*fal\.media\z/D', $host), 422, 'Unexpected segmentation asset host.');
        $addresses = $this->addresses($host);
        abort_unless(count($addresses) > 0 && count($addresses) <= 32, 422, 'Segmentation asset DNS is unavailable.');
        foreach ($addresses as $address) abort_unless($this->publicAddress($address), 422, 'Segmentation asset address is not public.');
        $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        try {
            $response = Http::connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 15,
                'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address], CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]])->get($url);
            if (! $response->successful()) throw new RuntimeException('Mask asset is unavailable.');
            return $this->bounded($response, self::MAX_MASK_BYTES);
        } catch (Throwable) { throw new RuntimeException('The segmentation asset could not be downloaded safely.'); }
    }

    protected function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return is_array($records) ? array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)))) : [];
    }
    private function publicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $binary = @inet_pton($ip);
        if (strlen($binary ?: '') === 4) {
            $first = ord($binary[0]); $second = ord($binary[1]);
            return $first < 224 && $first !== 0 && $first !== 127 && ! ($first === 100 && $second >= 64 && $second <= 127)
                && ! ($first === 169 && $second === 254);
        }
        $hex = bin2hex($binary ?: '');
        return str_starts_with($hex, '2') && ! str_starts_with($hex, '2002') && ! str_starts_with($hex, '20010000');
    }
    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Key '.config('services.fal.key')])->acceptJson()->connectTimeout(10)->timeout(60)
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 15, 'proxy' => '']);
    }
    private function bounded(\Illuminate\Http\Client\Response $response, int $limit): string
    {
        $stream = $response->toPsrResponse()->getBody(); $bytes = '';
        try {
            $length = $response->header('Content-Length');
            if ($length !== null && $length !== '' && (! ctype_digit($length) || (int) $length > $limit)) throw new RuntimeException('Provider response exceeds limit.');
            while (! $stream->eof()) { $part = $stream->read(min(65536, $limit - strlen($bytes) + 1)); if ($part === '') break; $bytes .= $part;
                if (strlen($bytes) > $limit) throw new RuntimeException('Provider response exceeds limit.'); }
            if (! $stream->eof()) throw new RuntimeException('Provider response was incomplete.');
            return $bytes;
        } finally { $stream->close(); }
    }
}
