<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Jobs\ScanMessageMedia;
use App\Models\MessageMedia;
use App\Services\Messaging\{MessageContentAccess, MessageMediaService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Storage};
use Illuminate\Validation\Rule;

class MessageMediaController extends Controller
{
    public function __construct(private MessageMediaService $media, private MessageContentAccess $access) {}
    public function create(Request $r)
    {
        $data = $r->validate(['id' => ['required', 'uuid'], 'channel' => ['required', Rule::in(['sms', 'email', 'shared', 'support'])],
            'name' => ['required', 'string', 'max:200', 'not_regex:~[\x00-\x1f\x7f/\\\\]~'],
            'size' => ['required', 'integer', 'min:1', 'max:'.MessageMediaService::MAX_BYTES]]);
        $this->access->channel($r->user(), $data['channel'], true);
        return Cache::lock('message-media-owner:'.$r->user()->id, 10)->block(3, function () use ($r, $data) {
            if ($file = MessageMedia::find($data['id'])) {
                abort_unless($file->user_id === $r->user()->id && $file->channel === $data['channel'] && $file->name === $data['name'] && $file->size === $data['size'], 409);
                // Returning the owned file lets the same send receipt reconcile after a lost response.
                // MessageSendController still rejects binding it to any new send key.
                abort_if(! $file->message_id && $file->expires_at->isPast(), 410);
                if ($file->status === 'uploading' && ! $file->message_id) { $this->media->prepareDirectory($file); }
                return response()->json($this->media->payload($file));
            }
            abort_if(MessageMedia::where('user_id', $r->user()->id)->whereNull('message_id')->where('expires_at', '>', now())->count() >= 20, 422, 'Finish or remove pending uploads first.');
            $file = MessageMedia::create([...$data, 'user_id' => $r->user()->id, 'expires_at' => now()->addDay()]);
            $this->media->prepareDirectory($file);
            return response()->json($this->media->payload($file), 201);
        });
    }
    public function show(Request $r, string $file) { return response()->json($this->media->payload($this->media->owned($r->user(), $file))); }
    public function chunk(Request $r, string $file, int $index)
    {
        $owned = $this->media->owned($r->user(), $file);
        return $this->media->locked($owned, function ($f) use ($r, $index) {
            abort_unless($f->status === 'uploading' && ! $f->message_id && $index >= 0 && $index < (int) ceil($f->size / MessageMediaService::CHUNK_BYTES), 409);
            $expected = min(MessageMediaService::CHUNK_BYTES, $f->size - $index * MessageMediaService::CHUNK_BYTES);
            abort_if((int) $r->header('Content-Length', 0) > $expected, 413);
            $root = Storage::disk('message_media')->path($f->directory());
            $temp = $root.'/part-'.bin2hex(random_bytes(12));
            $input = $r->getContent(true); $out = fopen($temp, 'xb');
            try {
                $copied = stream_copy_to_stream($input, $out, $expected + 1);
                fclose($out); $out = null;
                abort_unless($copied === $expected, 422, 'The upload chunk is incomplete or exceeds its declared size.');
                $part = $root.'/chunk-'.$index;
                if (is_file($part)) {
                    abort_unless(hash_file('sha256', $part) === hash_file('sha256', $temp), 409, 'This chunk contains different bytes.');
                    // A worker can stop after the atomic rename but before the DB acknowledgement.
                    // Recover that exact chunk without skipping ahead or counting it twice.
                    abort_if($index * MessageMediaService::CHUNK_BYTES > $f->received_bytes, 409, 'Resume at the acknowledged upload position.');
                } else {
                    abort_unless($index * MessageMediaService::CHUNK_BYTES === $f->received_bytes, 409, 'Resume at the acknowledged upload position.');
                    abort_unless(rename($temp, $part), 503);
                    chmod($part, 0660);
                }
                if ($index * MessageMediaService::CHUNK_BYTES === $f->received_bytes) {
                    $f->update(['received_bytes' => $f->received_bytes + $expected, 'expires_at' => now()->addDay()]);
                }
            } finally { if (is_resource($out)) { fclose($out); } if (is_resource($input)) { fclose($input); } if (is_file($temp)) { unlink($temp); } }
            return response()->json($this->media->payload($f));
        });
    }
    public function complete(Request $r, string $file)
    {
        $owned = $this->media->owned($r->user(), $file);
        $dispatch = $this->media->locked($owned, function ($f) {
            if (in_array($f->status, ['ready', 'scanning'], true)) { return false; }
            abort_unless(in_array($f->status, ['uploading', 'scan_failed'], true) && $f->received_bytes === $f->size, 409, 'Finish uploading before sending.');
            $f->update(['status' => 'scanning']);
            return true;
        });
        if ($dispatch) { ScanMessageMedia::dispatch($owned->id); }
        return response()->json($this->media->payload($owned->fresh()), 202);
    }
    public function destroy(Request $r, string $file)
    {
        $this->media->locked($this->media->owned($r->user(), $file), function ($f) {
            abort_if($f->message_id || $f->send_request_key, 409, 'A sent attachment cannot be removed from the upload tray.');
            $f->update(['status' => 'cancelled', 'expires_at' => now()]);
            Storage::disk('message_media')->deleteDirectory($f->directory());
        });
        return response()->noContent();
    }
    public function download(Request $r, MessageMedia $mediaFile)
    {
        $file = $mediaFile;
        if ($file->message_id) { $this->access->message($r->user(), $file->message_kind, $file->message_id); }
        else { $this->media->owned($r->user(), $file->id); }
        return $this->media->download($file, $r->boolean('preview'));
    }
    public function shared(Request $r, MessageMedia $mediaFile)
    {
        $file = $mediaFile;
        abort_unless($r->hasValidSignature() && $file->message_id && $file->share_expires_at?->isFuture(), 404);
        $exists = match ($file->message_kind) {
            'sms', 'email' => \App\Models\Message::whereKey($file->message_id)->where('channel', strtoupper($file->message_kind))->exists(),
            'shared' => \App\Models\SmsSharedMessage::whereKey($file->message_id)->exists(),
            default => false,
        };
        abort_unless($exists, 404);
        return $this->media->download($file);
    }
    public function legacy(Request $r, string $kind, string $id, int $index)
    {
        $message = $this->access->message($r->user(), $kind, $id);
        $visible = $this->media->legacyFiles($message, $kind);
        abort_unless(isset($visible[$index]), 404);
        $entry = $message->attachments_json[$index];
        $disk = Storage::disk($entry['disk'] ?? 'local'); $path = $entry['storage_path'];
        abort_unless($disk->exists($path), 404);
        return $disk->download($path, $visible[$index]['name'], [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
