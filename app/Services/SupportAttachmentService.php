<?php

namespace App\Services;

use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportAttachmentService
{
    public static function rules(): array
    {
        return ['attachments' => ['nullable', 'array', 'max:5'], 'attachments.*' => ['file', 'max:10240']];
    }

    public function submit(Request $request, array $data, callable $save): mixed
    {
        $stored = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('support-attachments', ['disk' => 'local', 'visibility' => 'private']);
                abort_unless(is_string($path) && $path !== '', 503, 'The attachment could not be saved.');
                $stored[] = ['name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'size' => $file->getSize(),
                    'type' => $file->getMimeType() ?: 'application/octet-stream', 'disk' => 'local', 'storage_path' => $path,
                    'sha256' => hash_file('sha256', $file->getRealPath())];
            }
            $result = $save([...$data, 'attachments_json' => $stored]);
        } finally {
            // Idempotent retries and rejected submissions must not orphan files.
            $retained = SupportTicketMessage::where('author_id', $request->user()->id)->where('request_key', $data['request_key'])
                ->get()->flatMap(fn ($message) => $message->attachments_json ?? [])->pluck('storage_path')->all();
            foreach ($stored as $attachment) {
                if (! in_array($attachment['storage_path'], $retained, true)) {
                    Storage::disk('local')->delete($attachment['storage_path']);
                }
            }
        }

        return $result;
    }

    public static function fingerprint(array $attachments): array
    {
        return array_map(fn ($file) => array_intersect_key($file, array_flip(['name', 'size', 'sha256'])), $attachments);
    }

    public function payload(SupportTicketMessage $message): array
    {
        return collect($message->attachments_json ?? [])->map(fn ($file, $index) => [
            'index' => $index, 'name' => $file['name'] ?? 'Attachment', 'size' => $file['size'] ?? null,
            'type' => $file['type'] ?? 'application/octet-stream',
            'download_url' => '/api/support/tickets/'.$message->support_ticket_id.'/messages/'.$message->id.'/attachments/'.$index,
        ])->values()->all();
    }
}
