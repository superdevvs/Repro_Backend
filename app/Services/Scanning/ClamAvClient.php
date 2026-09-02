<?php

namespace App\Services\Scanning;

use App\Exceptions\Scanning\ClamAvUnavailable;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lightweight, dependency-free client for a self-hosted ClamAV daemon (clamd).
 *
 * Connects over a unix socket (preferred when configured) or TCP and submits a
 * file using the clamd INSTREAM command, returning a clean/infected verdict.
 * A connect failure (or an interrupted stream) is surfaced as
 * {@see ClamAvUnavailable} so the Scan_Job keeps the file quarantined and
 * retries (Req 14.2, 15.2).
 *
 * INSTREAM protocol: send "zINSTREAM\0", then for each chunk send a 4-byte
 * network-order length prefix followed by the chunk bytes, then a zero-length
 * (4 zero bytes) chunk to terminate. clamd replies with e.g. "stream: OK\0" or
 * "stream: <Signature> FOUND\0" or "... ERROR\0".
 */
class ClamAvClient
{
    /** @var array{socket: ?string, host: string, port: int, connect_timeout: int, read_timeout: int, chunk_size: int, local_fallback_enabled: bool, local_binary: string, local_max_file_bytes: int, local_timeout: int, local_max_scantime_ms: int} */
    private array $config;

    /**
     * @param array<string, mixed>|null $config Overrides; defaults read from config('clamav').
     */
    public function __construct(?array $config = null)
    {
        $config ??= (array) config('clamav', []);

        $this->config = [
            'socket' => $config['socket'] ?? null,
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 3310),
            'connect_timeout' => (int) ($config['connect_timeout'] ?? 10),
            'read_timeout' => (int) ($config['read_timeout'] ?? 60),
            'chunk_size' => max(1024, (int) ($config['chunk_size'] ?? 8192)),
            // clamd's INSTREAM default is commonly only 25 MiB, below a normal
            // modern CR3. When that exact limit is reported, fall back to the
            // local ClamAV engine with an explicit, bounded max size instead of
            // retrying the same impossible stream eight times.
            'local_fallback_enabled' => (bool) ($config['local_fallback_enabled'] ?? true),
            'local_binary' => (string) ($config['local_binary'] ?? 'clamscan'),
            'local_max_file_bytes' => max(1, (int) ($config['local_max_file_bytes'] ?? 268435456)),
            'local_timeout' => max(1, (int) ($config['local_timeout'] ?? 600)),
            'local_max_scantime_ms' => max(1, (int) ($config['local_max_scantime_ms'] ?? 300000)),
        ];
    }

    /**
     * Scan a file by path or an open readable stream resource.
     *
     * @param string|resource $pathOrStream Absolute file path, or a readable stream resource.
     *
     * @throws ClamAvUnavailable When clamd cannot be reached or the stream is interrupted.
     * @throws InvalidArgumentException When the argument is neither a readable path nor a stream.
     */
    public function scan($pathOrStream): ClamAvScanResult
    {
        if (is_resource($pathOrStream)) {
            return $this->scanStream($pathOrStream);
        }

        if (is_string($pathOrStream)) {
            if (! is_file($pathOrStream) || ! is_readable($pathOrStream)) {
                throw new InvalidArgumentException("File not found or not readable: {$pathOrStream}");
            }

            $handle = @fopen($pathOrStream, 'rb');
            if ($handle === false) {
                throw new InvalidArgumentException("Unable to open file for scanning: {$pathOrStream}");
            }

            try {
                try {
                    return $this->scanStream($handle);
                } catch (ClamAvUnavailable $exception) {
                    if (! $this->shouldUseLocalFallback($exception)) {
                        throw $exception;
                    }
                }
            } finally {
                fclose($handle);
            }

            return $this->scanWithLocalCli($pathOrStream);
        }

        throw new InvalidArgumentException('scan() expects a file path string or a stream resource.');
    }

    /**
     * Submit an open stream to clamd via INSTREAM and interpret the verdict.
     *
     * @param resource $stream
     *
     * @throws ClamAvUnavailable
     */
    private function scanStream($stream): ClamAvScanResult
    {
        $socket = $this->connect();

        try {
            $this->write($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, $this->config['chunk_size']);
                if ($chunk === false) {
                    throw new ClamAvUnavailable('Failed reading file contents while streaming to ClamAV.');
                }
                if ($chunk === '') {
                    continue;
                }
                // 4-byte big-endian length prefix followed by the chunk bytes.
                $this->write($socket, pack('N', strlen($chunk)) . $chunk);
            }

            // Zero-length chunk terminates the stream.
            $this->write($socket, pack('N', 0));

            $response = $this->readResponse($socket);
        } finally {
            @fclose($socket);
        }

        return $this->interpret($response);
    }

    /**
     * Open a connection to clamd (unix socket preferred, else TCP).
     *
     * @return resource
     *
     * @throws ClamAvUnavailable
     */
    private function connect()
    {
        $remote = $this->remoteAddress();

        $errno = 0;
        $errstr = '';

        try {
            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                max(1, $this->config['connect_timeout']),
                STREAM_CLIENT_CONNECT
            );
        } catch (Throwable $e) {
            throw new ClamAvUnavailable("Unable to connect to ClamAV at {$remote}: {$e->getMessage()}", 0, $e);
        }

        if ($socket === false) {
            throw new ClamAvUnavailable("Unable to connect to ClamAV at {$remote}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, max(1, $this->config['read_timeout']));

        return $socket;
    }

    private function remoteAddress(): string
    {
        $socketPath = $this->config['socket'];
        if (is_string($socketPath) && $socketPath !== '') {
            return 'unix://' . $socketPath;
        }

        return sprintf('tcp://%s:%d', $this->config['host'], $this->config['port']);
    }

    /**
     * @param resource $socket
     *
     * @throws ClamAvUnavailable
     */
    private function write($socket, string $payload): void
    {
        $total = strlen($payload);
        $written = 0;

        while ($written < $total) {
            $bytes = @fwrite($socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                if ($this->timedOut($socket)) {
                    throw new ClamAvUnavailable('Timed out writing to ClamAV stream.');
                }
                throw new ClamAvUnavailable('Connection to ClamAV was interrupted while sending data.');
            }
            $written += $bytes;
        }
    }

    /**
     * @param resource $socket
     *
     * @throws ClamAvUnavailable
     */
    private function readResponse($socket): string
    {
        $response = '';

        while (! feof($socket)) {
            $buffer = @fread($socket, 4096);
            if ($buffer === false) {
                if ($this->timedOut($socket)) {
                    throw new ClamAvUnavailable('Timed out reading the ClamAV response.');
                }
                throw new ClamAvUnavailable('Connection to ClamAV was interrupted while reading the response.');
            }
            if ($buffer === '') {
                if ($this->timedOut($socket)) {
                    throw new ClamAvUnavailable('Timed out reading the ClamAV response.');
                }
                break;
            }
            $response .= $buffer;

            // clamd null-terminates the z-prefixed reply.
            if (str_contains($response, "\0")) {
                break;
            }
        }

        if ($this->timedOut($socket)) {
            throw new ClamAvUnavailable('Timed out reading the ClamAV response.');
        }

        return trim(str_replace("\0", '', $response));
    }

    /**
     * @param resource $socket
     */
    private function timedOut($socket): bool
    {
        $meta = stream_get_meta_data($socket);

        return ! empty($meta['timed_out']);
    }

    /**
     * @throws ClamAvUnavailable On an ERROR response from clamd.
     */
    private function interpret(string $response): ClamAvScanResult
    {
        if ($response === '') {
            throw new ClamAvUnavailable('Empty response from ClamAV.');
        }

        // Infected: "stream: <Signature> FOUND"
        if (str_ends_with($response, 'FOUND')) {
            $signature = trim((string) preg_replace('/^stream:\s*/i', '', $response));
            $signature = trim((string) preg_replace('/\s*FOUND$/', '', $signature));

            return ClamAvScanResult::infected($signature !== '' ? $signature : 'Unknown', $response);
        }

        // Clean: "stream: OK"
        if (str_ends_with($response, 'OK')) {
            return ClamAvScanResult::clean($response);
        }

        // Anything else (e.g. "... ERROR", size-limit exceeded) is treated as a
        // scan that could not complete -> keep quarantined and retry.
        throw new ClamAvUnavailable("ClamAV reported an error: {$response}");
    }

    protected function shouldUseLocalFallback(ClamAvUnavailable $exception): bool
    {
        return $this->config['local_fallback_enabled']
            && str_contains(strtolower($exception->getMessage()), 'instream size limit exceeded');
    }

    /**
     * Scan a large local file without clamd's INSTREAM transport ceiling.
     *
     * The binary is invoked with an argument array (never a shell), a strict
     * file-size ceiling, and a process timeout. Exit code 0 is clean, 1 is an
     * infection, and every other result remains fail-closed.
     */
    protected function scanWithLocalCli(string $path): ClamAvScanResult
    {
        $size = @filesize($path);
        if ($size === false || $size > $this->config['local_max_file_bytes']) {
            throw new ClamAvUnavailable('File exceeds the configured local ClamAV fallback size limit.');
        }

        $command = [
            $this->config['local_binary'],
            '--no-summary',
            '--stdout',
            '--max-filesize='.$this->config['local_max_file_bytes'],
            '--max-scansize='.$this->config['local_max_file_bytes'],
            '--max-scantime='.$this->config['local_max_scantime_ms'],
            $path,
        ];

        try {
            [$exitCode, $stdout, $stderr] = $this->runLocalScan($command);
        } catch (Throwable $exception) {
            throw new ClamAvUnavailable(
                'Local ClamAV fallback could not start: '.$exception->getMessage(),
                0,
                $exception
            );
        }

        $raw = trim($stdout."\n".$stderr);
        if ($exitCode === 0) {
            return ClamAvScanResult::clean($raw !== '' ? $raw : 'local clamscan: OK');
        }

        if ($exitCode === 1 && preg_match('/^.*:\s*(.+?)\s+FOUND\s*$/im', $raw, $matches)) {
            return ClamAvScanResult::infected(trim($matches[1]), $raw);
        }

        throw new ClamAvUnavailable(
            'Local ClamAV fallback reported an error'.($raw !== '' ? ': '.$raw : '.')
        );
    }

    /** @return array{0:int,1:string,2:string} */
    protected function runLocalScan(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout($this->config['local_timeout']);
        $process->run();

        return [$process->getExitCode() ?? 2, $process->getOutput(), $process->getErrorOutput()];
    }
}
