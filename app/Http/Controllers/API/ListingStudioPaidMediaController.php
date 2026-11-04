<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioOrder;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioPaidStorage;
use Illuminate\Http\Request;

class ListingStudioPaidMediaController extends Controller
{
    public function upload(Request $request, ListingStudioPaidMedia $media)
    {
        $request->merge(['requestKey' => $request->header('Idempotency-Key')]);
        $input = $request->validate(['photo' => ['required', 'file', 'max:10240'], 'requestKey' => ['required', 'uuid']]);
        $upload = $media->upload($request->user(), $request->file('photo'), $input['requestKey']);

        return response()->json(['success' => true, 'data' => ['upload' => $media->presentUpload($upload)]]);
    }

    public function uploadPreview(Request $request, string $upload, ListingStudioPaidMedia $media)
    {
        $record = $media->ownedUpload($request->user(), $upload);
        abort_unless($record->preview_path && str_starts_with($record->preview_path, 'uploads/'.$record->id.'/'), 404);

        return $this->image($media->read(['disk' => ListingStudioPaidStorage::DISK, 'path' => $record->preview_path]));
    }

    public function preview(Request $request, string $order, ListingStudioPaidMedia $media)
    {
        $input = $request->validate(['variant' => ['sometimes', 'in:original,result'], 'version' => ['sometimes', 'string', 'max:40', 'regex:/\A(?:initial|[a-f0-9-]{36})\z/i']]);
        $record = ListingStudioOrder::where('client_id', $request->user()->id)->with('item')->findOrFail($order);
        $item = $record->item;
        $descriptor = ($input['variant'] ?? 'result') === 'original' ? $item->source_snapshot
            : ($item->status === 'completed' ? app(\App\Services\ListingStudio\ListingStudioAiRevisionService::class)->version($item, $input['version'] ?? null) : null);
        abort_unless($descriptor, 404);

        // The immutable snapshot is already authorized by the order; it can still point to the
        // input upload while its queued materialization is pending.
        return $this->image($media->previewBytes($media->read($descriptor)));
    }

    public function download(Request $request, string $order, ListingStudioPaidMedia $media)
    {
        $input = $request->validate(['version' => ['sometimes', 'string', 'max:40', 'regex:/\A(?:initial|[a-f0-9-]{36})\z/i']]);
        $record = ListingStudioOrder::where('client_id', $request->user()->id)->with('item')->findOrFail($order);
        abort_unless($record->item?->status === 'completed' && $record->item->output_descriptor, 404);
        $descriptor = app(\App\Services\ListingStudio\ListingStudioAiRevisionService::class)->version($record->item, $input['version'] ?? null);
        $bytes = $media->read($descriptor);

        return response($bytes)->withHeaders(['Content-Type' => $descriptor['mime'],
            'Content-Disposition' => 'attachment; filename="listing-studio-'.$record->id.'.'.$media->extension($descriptor['mime']).'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function image(string $bytes)
    {
        return response($bytes)->withHeaders(['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
