<?php

namespace App\Jobs;

use App\Models\MessageMedia;
use App\Services\Messaging\MessageMediaService;
use App\Services\Studio\Providers\FotelloTransferUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Http, Storage};

/** Download signed-provider media once, using a pinned public IP and bounded streaming. */
class ImportMessageMedia implements ShouldQueue
{
    use Queueable;
    public int $tries = 4;
    public int $timeout = 600;
    public function __construct(public string $fileID) { $this->afterCommit(); }
    public function backoff(): array { return [15, 60, 180]; }
    public function handle(): void
    {
        $file = MessageMedia::find($this->fileID);
        if (! $file || ! in_array($file->status, ['importing', 'scanning'], true)) { return; }
        if ($file->status === 'importing') {
            app(MessageMediaService::class)->locked($file, function ($f) {
                if ($f->status !== 'importing') { return; }
                $options = app(FotelloTransferUrl::class)->requestOptions($f->source_url);
                $response = Http::withOptions([...$options, 'stream' => true])->timeout(120)->connectTimeout(10)->get($f->source_url);
                abort_unless($response->status() === 200 && (int) $response->header('Content-Length') <= 50 * 1024 * 1024, 502);
                app(MessageMediaService::class)->prepareDirectory($f);
                $temp = $f->path().'.incoming';
                $output = fopen($temp, 'wb'); $input = $response->toPsrResponse()->getBody(); $size = 0;
                try {
                    while (! $input->eof()) {
                        $part = $input->read(65536); $size += strlen($part);
                        abort_if($size > 50 * 1024 * 1024, 413);
                        if (fwrite($output, $part) !== strlen($part)) { throw new \RuntimeException('Unable to save message media.'); }
                    }
                } catch (\Throwable $error) { fclose($output); $output = null; @unlink($temp); throw $error; }
                finally { if (is_resource($output)) { fclose($output); } $input->close(); }
                abort_unless($size > 0 && rename($temp, $f->path()), 502);
                chmod($f->path(), 0660);
                $f->update(['size' => $size, 'received_bytes' => $size, 'status' => 'scanning', 'source_url' => null]);
            });
        }
        app(MessageMediaService::class)->scan($file->fresh());
    }
    public function failed(?\Throwable $error): void
    {
        MessageMedia::whereKey($this->fileID)->whereIn('status', ['importing', 'scanning'])->update(['status' => 'unavailable']);
    }
}
