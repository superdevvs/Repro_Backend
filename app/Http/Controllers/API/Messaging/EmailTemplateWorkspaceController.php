<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateEmailTemplateArtwork;
use App\Models\MessageTemplate;
use App\Services\Messaging\EmailTemplateAssistant;
use App\Services\Messaging\EmailTemplateDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailTemplateWorkspaceController extends Controller
{
    public function assist(Request $request, EmailTemplateAssistant $assistant): JsonResponse
    {
        $data = $request->validate(array_merge([
            'videos' => ['sometimes','array','max:6'], 'videos.*.url' => ['required','url:https','max:2000'], 'videos.*.asset_id' => ['nullable','uuid'],
            'asset_ids' => ['sometimes','array','max:12'], 'asset_ids.*' => ['uuid'], 'video_urls' => ['sometimes','array','max:6'], 'video_urls.*' => ['url:https','max:2000'],
            'template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'instruction' => ['required', 'string', 'max:4000'],
            'scope' => ['required', 'in:email,subject,section'],
            'section_id' => ['nullable', 'string', 'max:150'],
            'draft' => ['required', 'array:channel,name,description,category,scope,subject,body_html,body_text,content_blocks_json,editor_document_json,email_type,override_enabled,workspace_revision'],
            'draft.name' => ['required', 'string', 'max:255'],
            'draft.channel' => ['sometimes', 'in:EMAIL'],
            'draft.description' => ['nullable', 'string', 'max:5000'],
            'draft.scope' => ['sometimes', 'in:SYSTEM,GLOBAL,ACCOUNT,USER'],
            'draft.category' => ['nullable', 'in:BOOKING,REMINDER,PAYMENT,INVOICE,ACCOUNT,GENERAL'],
            'draft.email_type' => ['nullable', 'string', 'max:100'],
            'draft.override_enabled' => ['sometimes', 'boolean'],
            'draft.subject' => ['nullable', 'string', 'max:255'],
            'draft.body_html' => ['nullable', 'string', 'max:250000'],
            'draft.body_text' => ['nullable', 'string', 'max:100000'],
            'draft.content_blocks_json' => ['nullable', 'array', 'max:150'],
            'draft.content_blocks_json.*' => ['array:body_html,body_text'],
            'draft.content_blocks_json.*.body_html' => ['required', 'string', 'max:20000'],
            'draft.content_blocks_json.*.body_text' => ['required', 'string', 'max:20000'],
        ], EmailTemplateDocument::rules('draft.')));
        $template = isset($data['template_id']) ? MessageTemplate::findOrFail($data['template_id']) : null;
        abort_if($template && $template->channel !== 'EMAIL', 422, 'Open an email template.');
        $selectedIds = $data['asset_ids'] ?? [];
        if (preg_match('/upload|my (?:photo|image)|image library|use.*(?:photo|image)/i', $data['instruction'])) $selectedIds = array_unique([...$selectedIds, ...DB::table('email_template_assets')->where('user_id', $request->user()->id)->where('status', 'ready')->where('archived', false)->latest('created_at')->limit(12)->pluck('id')->all()]);
        preg_match_all('/[a-zA-Z0-9_.-]+\.(?:jpg|jpeg|png|webp)\b/i', $data['instruction'], $filenames);
        if ($filenames[0] !== []) $selectedIds = array_unique([...$selectedIds, ...DB::table('email_template_assets')->where('user_id', $request->user()->id)->where('status', 'ready')->where('archived', false)->whereIn('name', $filenames[0])->limit(12)->pluck('id')->all()]);
        $media = DB::table('email_template_assets')->where('user_id', $request->user()->id)->whereIn('status', ['ready','processing'])->whereIn('id', $selectedIds)->get()->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'url' => $row->path ? \App\Services\Messaging\EmailWorkspaceAssets::publicUrl($row->path) : null, 'source' => $row->source])->all();
        foreach ($data['videos'] ?? [] as $video) { $asset = collect($media)->firstWhere('id', $video['asset_id'] ?? null); if ($asset) $media[] = ['video_url' => $video['url'], 'media_id' => $asset['id'], 'image_url' => $asset['url']]; }
        foreach ($data['video_urls'] ?? [] as $url) $media[] = ['video_url' => $url];
        try {
            $context = $assistant->context(trim($data['instruction']), $data['draft'], $request->user());
            $proposal = $assistant->suggest($data['draft'], trim($data['instruction']), $data['scope'], $data['section_id'] ?? null, $template, $context, $media);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return response()->json(['message' => 'Robbie could not prepare that revision. Your draft is unchanged. Try again.'], 502);
        }

        return response()->json(['draft' => $proposal, 'context' => ['message' => $assistant->message, 'needs_input' => $assistant->needsInput, 'model' => config('services.openai.email_model', 'gpt-6-astra'),
            'sources' => array_map(fn ($article) => ['id' => $article['id'], 'title' => $article['title'], 'url' => $article['url']], $context)]]);
    }

    public function artwork(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'kind' => ['required', 'in:illustration,icon,header'], 'prompt' => ['required', 'string', 'max:2000']]);
        abort_unless(filled(config('services.openai.api_key')), 503, 'OpenAI image generation is not configured.');
        $created = DB::table('email_template_artworks')->insertOrIgnore([
            'id' => $data['id'], 'user_id' => $request->user()->id, 'kind' => $data['kind'], 'prompt' => $data['prompt'],
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('email_template_artworks')->where('id', $data['id'])->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        abort_if($row->prompt !== $data['prompt'] || $row->kind !== $data['kind'], 409, 'This generation ID belongs to another request.');
        if ($created) {
            GenerateEmailTemplateArtwork::dispatch($data['id']);
        }

        return response()->json($this->artworkData($row), 202);
    }

    public function artworkStatus(Request $request, string $artwork): JsonResponse
    {
        $row = DB::table('email_template_artworks')->where('id', $artwork)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);

        return response()->json($this->artworkData($row));
    }

    private function artworkData(object $row): array
    {
        $stale = in_array($row->status, ['processing', 'queued'], true) && now()->diffInSeconds(\Carbon\Carbon::parse($row->updated_at), true) > 600;

        $optimized = DB::table('email_template_assets')->where('artwork_id', $row->id)->where('status', 'ready')->first();
        return ['id' => $row->id, 'kind' => $row->kind, 'status' => $stale ? 'unknown' : $row->status,
            'url' => ($optimized?->path ?? $row->path) ? \App\Services\Messaging\EmailWorkspaceAssets::publicUrl($optimized?->path ?? $row->path) : null,
            'error' => $stale ? 'Generation could not be confirmed. Check again before starting another request.' : $row->error];
    }
}
