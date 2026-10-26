<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\LinkPreview\LinkPreviewService;
use App\Services\Messaging\EmailWorkspaceAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class EmailWorkspaceMediaController extends Controller
{
    public function capabilities()
    {
        return response()->json(['version' => 2, 'enabled' => filter_var(DB::table('settings')->where('key', 'email_workspace_v2_enabled')->value('value') ?? config('email_workspace.enabled'), FILTER_VALIDATE_BOOLEAN)]);
    }

    public function index(Request $request, EmailWorkspaceAssets $assets)
    {
        $data = $request->validate(['search' => ['nullable','string','max:150'], 'source' => ['nullable','in:uploaded,generated,dashboard,video-thumbnail'], 'page' => ['nullable','integer','min:1']]);
        $rows = DB::table('email_template_assets')->where('user_id', $request->user()->id)->where('archived', false)
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($data['source'] ?? '', fn ($q, $source) => $q->where('source', $source))->orderByDesc('created_at')->paginate(24);
        return response()->json(['data' => $rows->getCollection()->map(fn ($row) => $assets->data($row)), 'last_page' => $rows->lastPage(), 'page' => $rows->currentPage()]);
    }

    public function store(Request $request, EmailWorkspaceAssets $assets)
    {
        $data = $request->validate(array_merge(['file' => ['required','file','mimes:jpg,jpeg,png,webp','max:10240']], $this->options()));
        $row = $assets->create($request->user()->id, $request->file('file')->get(), $request->file('file')->getClientOriginalName(), \Illuminate\Support\Arr::except($data, 'file'));
        return response()->json($assets->data($row), 202);
    }

    public function show(Request $request, string $asset, EmailWorkspaceAssets $assets)
    {
        return response()->json($assets->data($this->owned($request, $asset, true)));
    }

    public function archive(Request $request, string $asset)
    {
        $this->owned($request, $asset);
        DB::table('email_template_assets')->where('id', $asset)->update(['archived' => true, 'updated_at' => now()]);
        return response()->json(['archived' => true]);
    }

    public function variant(Request $request, string $asset, EmailWorkspaceAssets $assets)
    {
        $data = $request->validate($this->options());
        $row = $this->owned($request, $asset, true);
        abort_unless($row->original_path || $row->path, 422, 'Upload the source image to create a new crop.');
        $bytes = $row->user_id === $request->user()->id && $row->original_path ? \Illuminate\Support\Facades\Storage::disk('local')->get($row->original_path) : \Illuminate\Support\Facades\Storage::disk('public')->get($row->path);
        return response()->json($assets->data($assets->create($request->user()->id, $bytes, $row->name, $data, $row->source)), 202);
    }

    public function dashboard(Request $request, LinkPreviewService $previews)
    {
        $data = $request->validate(['search' => ['nullable','string','max:150'], 'shoot_id' => ['nullable','integer']]);
        $items = [];
        $shoots = Shoot::query()->with('units')->where(fn ($q) => $q->whereIn('status', ['delivered','completed'])->orWhere('delivery_status', 'delivered'))->when($data['shoot_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where('address', 'like', '%'.$search.'%'))->latest('id')->limit(20)->get();
        foreach ($shoots as $shoot) {
            try {
                $units = $shoot->units->isEmpty() ? [null] : $shoot->units->take(12)->all();
                foreach ($units as $unit) {
                    $preview = $previews->forShoot($shoot, 'branded', null, $unit);
                    if (! $preview->photoCount && ! $preview->videoUrl) continue;
                    foreach (array_unique(array_filter([$preview->hero, ...$preview->gallery])) as $index => $url) $items[] = ['id' => $shoot->id.':'.($unit?->id ?? 0).':'.$index, 'shoot_id' => $shoot->id, 'unit_id' => $unit?->id, 'name' => $preview->addressLine ?? $shoot->address, 'url' => $url, 'video_url' => $preview->videoUrl, 'watch_url' => $preview->videoUrl ? $preview->url : null];
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { /* Unreleased/unit-scoped media is not a source. */ }
        }
        return response()->json($items);
    }

    public function importDashboard(Request $request, LinkPreviewService $previews, EmailWorkspaceAssets $assets)
    {
        $data = $request->validate(array_merge(['shoot_id' => ['required','integer'], 'unit_id' => ['nullable','integer'], 'url' => ['required','string','max:2000']], $this->options()));
        $shoot = Shoot::where(fn ($q) => $q->whereIn('status', ['delivered','completed'])->orWhere('delivery_status', 'delivered'))->findOrFail($data['shoot_id']);
        $unit = isset($data['unit_id']) ? $shoot->units()->findOrFail($data['unit_id']) : null;
        $preview = $previews->forShoot($shoot, 'branded', null, $unit);
        $source = collect(array_filter([$preview->hero, ...$preview->gallery]))->first(fn ($url) => $this->sourceKey($url) === $this->sourceKey($data['url']));
        abort_unless($source, 403, 'Select currently released dashboard media.');
        return response()->json($assets->data($assets->importUrl($request->user()->id, $source, $shoot->address, $data, 'dashboard')), 202);
    }

    public function video(Request $request, EmailWorkspaceAssets $assets)
    {
        $data = $request->validate(['url' => ['required','url:https','max:2000']]);
        $url = $data['url']; $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
        abort_if(isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.'), 422, 'Use a public hosted video link.');
        $thumbnail = null; $title = 'Watch video'; $provider = 'link';
        if (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','youtu.be'], true)) {
            parse_str($parts['query'] ?? '', $query);
            $id = $host === 'youtu.be' ? trim($parts['path'] ?? '', '/') : ($query['v'] ?? preg_replace('~^/(?:shorts|embed)/~', '', $parts['path'] ?? ''));
            if (! preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id)) throw ValidationException::withMessages(['url' => 'Paste a YouTube video link.']);
            $url = 'https://www.youtube.com/watch?v='.$id; $thumbnail = 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg'; $provider = 'youtube';
        } elseif (in_array($host, ['vimeo.com','www.vimeo.com','player.vimeo.com'], true)) {
            try {
                $result = Http::timeout(8)->withOptions(['allow_redirects' => false])->get('https://vimeo.com/api/oembed.json', ['url' => $url]);
                if ($result->successful()) { $thumbnail = $result->json('thumbnail_url'); $title = mb_substr((string) $result->json('title', $title), 0, 255); }
            } catch (\Illuminate\Http\Client\ConnectionException) {
                // The video remains usable when provider metadata is unavailable;
                // users can upload their own thumbnail instead.
            }
            $provider = 'vimeo';
        } elseif (in_array($host, ['loom.com','www.loom.com'], true)) $provider = 'loom';
        // Arbitrary video URLs are never fetched. Only provider-owned thumbnail
        // CDNs pass the existing bounded image loader; missing posters stay editable.
        $asset = null;
        if (is_string($thumbnail)) {
            try { $asset = $assets->data($assets->importUrl($request->user()->id, $thumbnail, $title, ['play' => true], 'video-thumbnail')); }
            catch (ValidationException) { /* A custom thumbnail remains available. */ }
        }
        return response()->json(['url' => $url, 'title' => $title, 'provider' => $provider, 'asset' => $asset, 'needs_thumbnail' => ! $asset]);
    }

    private function sourceKey(string $url): string
    {
        $parts = parse_url($url); parse_str($parts['query'] ?? '', $query); unset($query['expires'], $query['signature']); ksort($query);
        return strtolower($parts['scheme'] ?? '').'://'.strtolower($parts['host'] ?? '').($parts['path'] ?? '').'?'.http_build_query($query);
    }

    private function owned(Request $request, string $id, bool $allowShared = false): object
    {
        $row = DB::table('email_template_assets')->where('id', $id)->where('user_id', $request->user()->id)->first();
        if (! $row && $allowShared && $request->filled('template_id')) {
            $template = \App\Models\MessageTemplate::find($request->integer('template_id'));
            if ($template && (in_array($template->scope, ['GLOBAL','SYSTEM','ACCOUNT'], true) || (int) $template->created_by === $request->user()->id || (int) $template->owner_id === $request->user()->id)) {
                $sections = $template->editor_document_json['sections'] ?? [];
                foreach ($sections as $section) foreach ($section['columns'] ?? [] as $column) $sections = array_merge($sections, $column);
                $sources = [(string) $template->body_html];
                foreach ($template->content_blocks_json ?? [] as $block) $sources[] = (string) ($block['body_html'] ?? '');
                $htmlReference = collect($sources)->contains(fn ($html) => preg_match('~\bdata-email-media-id\s*=\s*["\']'.preg_quote($id, '~').'["\']~i', $html));
                if ($htmlReference || collect($sections)->contains(fn ($section) => ($section['media_id'] ?? null) === $id)) $row = DB::table('email_template_assets')->where('id', $id)->where('status', 'ready')->first();
            }
        }
        abort_unless($row, 404);
        return $row;
    }

    private function options(): array
    {
        return ['slot' => ['sometimes','in:header,content,icon'], 'quality' => ['sometimes','in:balanced,high'], 'crop' => ['nullable','in:1:1,3:2,16:9,12:5'], 'focal_x' => ['sometimes','integer','min:0','max:100'], 'focal_y' => ['sometimes','integer','min:0','max:100'], 'play' => ['sometimes','boolean']];
    }
}
