<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\IguideLabImport;
use App\Models\IguideLabImportUpload;
use App\Services\IguideLab\LabImports;
use App\Services\IguideLab\LabImportUploads;
use App\Services\IguideLab\LabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IguideLabImportController extends Controller
{
    public function startUpload(Request $request, LabImportUploads $uploads)
    {
        abort_if(array_diff(array_keys($request->all()), ['requestId', 'importRequestId', 'filename', 'sizeBytes', 'sha256', 'metadataSha256']), 422, 'Unknown upload field.');
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'importRequestId' => ['required', 'uuid'],
            'filename' => ['required', 'string', 'max:200', 'regex:/^[^\/\\\\\x00-\x1f]+\.zip$/iD'], 'sizeBytes' => ['required', 'integer', 'min:1', 'max:1073741824'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'metadataSha256' => ['sometimes', 'nullable', 'regex:/^[a-f0-9]{64}$/D']]);
        return response()->json(['data' => $uploads->dto($uploads->start($request->attributes->get('iguide_lab_project'), $input))], 201);
    }
    public function showUpload(Request $request, string $id, LabImportUploads $uploads)
    {
        $uploads->prune($request->attributes->get('iguide_lab_project')->id);
        return response()->json(['data' => $uploads->dto($this->upload($request, $id))]);
    }
    public function uploadChunk(Request $request, string $id, int $index, LabImportUploads $uploads)
    {
        abort_unless(strtolower(explode(';', $request->header('Content-Type', ''))[0]) === 'application/octet-stream', 415, 'Chunks require application/octet-stream.');
        return response()->json(['data' => $uploads->dto($uploads->chunk($this->upload($request, $id), $index,
            $request->header('Content-Range', ''), $request->header('X-Chunk-SHA256', ''), $request->getContent(true)))]);
    }
    public function finalizeUpload(Request $request, string $id, LabImportUploads $uploads)
    {
        abort_if(array_diff(array_keys($request->all()), ['metadata']), 422, 'Unknown finalization field.');
        $request->validate(['metadata' => ['sometimes', 'file', 'max:8192']]);
        return response()->json(['data' => $uploads->dto($uploads->finalize($this->upload($request, $id), $request->file('metadata')))], 202);
    }
    private function upload(Request $request, string $id): IguideLabImportUpload
    {
        return IguideLabImportUpload::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id);
    }
    public function preview(Request $request, string $id, string $caseId)
    {
        $record = IguideLabImport::where('project_id', $request->attributes->get('iguide_lab_project')->id)->where('status', 'completed')->findOrFail($id);
        $source = collect($record->sources)->firstWhere('caseId', $caseId); abort_unless($source && isset($source['previewPath']), 404);
        $response = Storage::disk(LabService::DISK)->response($source['previewPath'], null, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        return $response;
    }
    public function store(Request $request, LabImports $imports)
    {
        abort_if(array_diff(array_keys($request->all()), ['requestId', 'archive', 'metadata', 'url']), 422, 'Unknown import field.');
        abort_unless($request->hasFile('archive') xor $request->filled('url'), 422, 'Provide exactly one archive or supported link.');
        abort_if($request->hasFile('metadata') && ! $request->hasFile('archive'), 422, 'Separate metadata requires a ZIP upload.');
        $input = $request->validate(['requestId' => ['required', 'uuid'], 'archive' => ['sometimes', 'file', 'extensions:zip', 'max:30720'],
            'metadata' => ['sometimes', 'file', 'max:8192'], 'url' => ['sometimes', 'string', 'max:5000']]);
        return response()->json(['data' => $imports->dto($imports->start($request->attributes->get('iguide_lab_project'), $input, $request->file('archive'), $request->file('metadata')))], 202);
    }
    public function index(Request $request, LabImports $imports)
    {
        return response()->json(['data' => IguideLabImport::where('project_id', $request->attributes->get('iguide_lab_project')->id)->latest()->limit(100)->get()->map(fn ($record) => $imports->dto($record))]);
    }
    public function show(Request $request, string $id, LabImports $imports)
    {
        return response()->json(['data' => $imports->dto(IguideLabImport::where('project_id', $request->attributes->get('iguide_lab_project')->id)->findOrFail($id))]);
    }
}
