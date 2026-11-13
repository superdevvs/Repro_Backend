<?php

namespace App\Services\Messaging;

use App\Models\{MessageMedia, User};
use Illuminate\Support\Facades\{Cache, Storage, URL};
use Illuminate\Validation\ValidationException;

class MessageMediaService
{
    public const MAX_BYTES = 1073741824;
    public const CHUNK_BYTES = 8388608;
    public const MAX_FILES = 10;

    public function prepareDirectory(MessageMedia $file): void
    {
        $disk = Storage::disk('message_media');
        $shared = config('filesystems.disks.message_media.shared_group');
        $group = $shared && function_exists('posix_getgrnam') ? posix_getgrnam($shared) : false;
        if ($shared && function_exists('posix_getgrnam') && ! $group) { throw new \RuntimeException('The message storage group is unavailable.'); }
        // Only this dedicated disk and the server-generated UUID directory are changed.
        foreach (['', $file->directory()] as $relative) {
            $path = $disk->path($relative);
            if (! is_dir($path) && ! @mkdir($path, 02770, true) && ! is_dir($path)) { throw new \RuntimeException('Unable to create message storage.'); }
            clearstatcache(true, $path);
            if ($group && filegroup($path) !== $group['gid'] && ! @chgrp($path, $group['gid'])) { throw new \RuntimeException('Message worker group access requires repair.'); }
            if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 07777) !== 02770 && ! @chmod($path, 02770)) { throw new \RuntimeException('Message storage permissions require repair.'); }
        }
    }

    public function incoming(string $kind, string $messageID, array $media): void
    {
        foreach (array_slice($media, 0, self::MAX_FILES) as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null)) { continue; }
            try { app(\App\Services\Studio\Providers\FotelloTransferUrl::class)->validate($entry['url']); }
            catch (\Throwable) { continue; }
            $type = (string) ($entry['content_type'] ?? 'application/octet-stream');
            $extension = match ($type) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'application/pdf' => 'pdf', default => 'bin' };
            $file = MessageMedia::firstOrCreate(['source_key' => hash('sha256', $kind.':'.$messageID.':'.$index)], [
                'channel' => $kind, 'message_kind' => $kind, 'message_id' => $messageID,
                'name' => 'Attachment-'.($index + 1).'.'.$extension, 'mime_type' => $type, 'size' => 0,
                'status' => 'importing', 'source_url' => $entry['url'], 'expires_at' => now()->addDays(30),
            ]);
            if ($file->wasRecentlyCreated) { \App\Jobs\ImportMessageMedia::dispatch($file->id); }
        }
    }

    public function owned(User $user, string $id): MessageMedia
    {
        $file = MessageMedia::where('user_id', $user->id)->findOrFail($id);
        app(MessageContentAccess::class)->channel($user, $file->channel, true);
        abort_if(! $file->message_id && $file->expires_at->isPast(), 410, 'This upload expired. Choose the file again.');
        return $file;
    }
    public function locked(MessageMedia $file, callable $run): mixed
    {
        return Cache::lock('message-media:'.$file->id, 900)->block(3, fn () => $run($file->fresh()));
    }
    public function payload(MessageMedia $file): array
    {
        return ['id' => $file->id, 'name' => $file->name, 'size' => $file->size, 'type' => $file->mime_type,
            'status' => $file->status, 'received_bytes' => $file->received_bytes, 'chunk_bytes' => self::CHUNK_BYTES,
            'download_url' => '/api/messaging/media/files/'.$file->id,
            'preview_url' => '/api/messaging/media/files/'.$file->id.'?preview=1'];
    }
    public function attachments(User $user, string $channel, array $ids): \Illuminate\Support\Collection
    {
        return collect($ids)->map(function ($id) use ($user, $channel) {
            $file = $this->owned($user, $id);
            abort_unless($file->channel === $channel && $file->status === 'ready' && ! $file->message_id && ! $file->send_request_key, 409, 'An attachment is not ready or has already been sent.');
            return $file;
        });
    }
    /** Keep existing web/email attachments usable without exposing their storage paths. */
    public function legacyFiles(mixed $message, string $kind): array
    {
        if (! in_array($kind, ['sms', 'email'], true)) { return []; }
        $files = [];
        foreach (array_slice($message->attachments_json ?? [], 0, self::MAX_FILES, true) as $index => $entry) {
            if (! is_array($entry)) { continue; }
            $path = $entry['storage_path'] ?? null; $disk = $entry['disk'] ?? 'local';
            if (! is_int($index) || ! is_string($path) || ! str_starts_with($path, 'messaging-attachments/')
                || str_contains($path, '..') || preg_match('/[\x00-\x1f\\\\]/', $path) || ! is_string($disk) || ! config('filesystems.disks.'.$disk)) { continue; }
            $name = basename((string) ($entry['name'] ?? 'Attachment'));
            if ($name === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $name)) { $name = 'Attachment'; }
            $files[$index] = ['id' => 'legacy-'.$kind.'-'.$message->id.'-'.$index, 'name' => $name,
                'type' => $entry['type'] ?? 'application/octet-stream', 'size' => (int) ($entry['size'] ?? 0), 'status' => 'ready',
                'download_url' => '/api/messaging/media/legacy/'.$kind.'/'.$message->id.'/'.$index];
        }
        return $files;
    }
    public function shareURL(MessageMedia $file): string
    {
        return URL::temporarySignedRoute('message-media.shared', $file->share_expires_at ?? now()->addDays(30), ['mediaFile' => $file->id]);
    }
    public function linkedBody(string $body, \Illuminate\Support\Collection $files): string
    {
        return trim($body.($files->isNotEmpty() ? "\n\n".$files->map(fn ($file) => $file->name."\n".$this->shareURL($file))->implode("\n") : ''));
    }
    public function bind(\Illuminate\Support\Collection $files, string $kind, string $id): void
    {
        foreach ($files as $file) { $file->update(['message_kind' => $kind, 'message_id' => $id]); }
    }
    public static function bindPending(string $kind, string $id, ?int $owner): void
    {
        $key = request()->attributes->get('message_media_send_key');
        if (! is_string($key) || ! $owner || (int) request()->user()?->id !== $owner) { return; }
        request()->attributes->set('message_media_record_created', true);
        MessageMedia::where('user_id', $owner)->where('channel', $kind)->where('send_request_key', $key)
            ->whereNull('message_id')->update(['message_kind' => $kind, 'message_id' => $id]);
    }
    public function scan(MessageMedia $file): void
    {
        $this->locked($file, function ($fresh) {
            if ($fresh->status !== 'scanning') { return; }
            if (! is_file($fresh->path())) {
                $temp = $fresh->path().'.assembling';
                $output = fopen($temp, 'wb');
                try {
                    for ($i = 0; $i < (int) ceil($fresh->size / self::CHUNK_BYTES); $i++) {
                        $part = Storage::disk('message_media')->path($fresh->directory().'/chunk-'.$i);
                        abort_unless(is_file($part), 409);
                        $input = fopen($part, 'rb');
                        try { stream_copy_to_stream($input, $output); } finally { fclose($input); }
                    }
                } finally { fclose($output); }
                abort_unless(filesize($temp) === $fresh->size && rename($temp, $fresh->path()), 409);
                chmod($fresh->path(), 0660);
                foreach (glob(Storage::disk('message_media')->path($fresh->directory().'/chunk-*')) as $part) { unlink($part); }
            }
            abort_unless(is_file($fresh->path()) && filesize($fresh->path()) === $fresh->size, 409);
            // Tests may disable scanning; production never releases an unscanned file.
            if (! app()->environment('testing') || config('clamav.scan_on_upload', true)) {
                $result = app(\App\Services\Scanning\ClamAvClient::class)->scan($fresh->path());
                if (! $result->isClean()) { $fresh->update(['status' => 'rejected']); return; }
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fresh->path()) ?: 'application/octet-stream';
            $fresh->update(['status' => 'ready', 'mime_type' => $mime, 'sha256' => hash_file('sha256', $fresh->path())]);
        });
    }
    public function download(MessageMedia $file, bool $preview = false): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($file->status === 'ready' && is_file($file->path()), 409, 'This attachment is not ready.');
        $inline = $preview && in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'audio/mpeg', 'audio/mp4']);
        return response()->file($file->path(), [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer',
        ])->setContentDisposition($inline ? 'inline' : 'attachment', $file->name, 'attachment');
    }
}
