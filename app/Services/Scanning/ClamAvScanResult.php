<?php

namespace App\Services\Scanning;

/**
 * Immutable verdict returned by {@see ClamAvClient::scan()}.
 *
 * A result is either clean or infected. When infected, {@see $signature} carries
 * the malware signature name reported by clamd (e.g. "Eicar-Test-Signature").
 */
final class ClamAvScanResult
{
    private function __construct(
        public readonly bool $clean,
        public readonly ?string $signature,
        public readonly string $raw,
    ) {
    }

    public static function clean(string $raw = 'stream: OK'): self
    {
        return new self(true, null, $raw);
    }

    public static function infected(string $signature, string $raw = ''): self
    {
        return new self(false, $signature, $raw !== '' ? $raw : "stream: {$signature} FOUND");
    }

    public function isClean(): bool
    {
        return $this->clean;
    }

    public function isInfected(): bool
    {
        return ! $this->clean;
    }

    /** Malware signature name when infected, null when clean. */
    public function signature(): ?string
    {
        return $this->signature;
    }

    /** Raw clamd response line, useful for persisting as scan_result. */
    public function raw(): string
    {
        return $this->raw;
    }
}
