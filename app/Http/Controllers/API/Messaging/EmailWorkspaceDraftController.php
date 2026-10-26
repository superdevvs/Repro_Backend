<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\Messaging\EmailTemplateDocument;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailWorkspaceDraftController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate(['template_id' => ['nullable','integer','min:1']]);
        $row = DB::table('email_template_drafts')->where('user_id', $request->user()->id)
            ->where('workspace_key', $this->workspaceKey($data['template_id'] ?? null))->first();
        return response()->json($row ? ['id' => $row->id, 'revision' => $row->revision, 'draft' => json_decode($row->draft, true), 'updated_at' => $row->updated_at] : null);
    }

    public function save(Request $request, string $draft)
    {
        abort_unless(Str::isUuid($draft), 404);
        $data = $request->validate(array_merge([
            'base_revision' => ['required','integer','min:0'], 'template_id' => ['nullable','integer','exists:message_templates,id'],
            'draft' => ['required','array:channel,name,description,category,scope,subject,body_html,body_text,content_blocks_json,editor_document_json,email_type,override_enabled,workspace_revision'],
            'draft.channel' => ['required','in:EMAIL'], 'draft.name' => ['required','string','max:255'],
            'draft.subject' => ['nullable','string','max:255'], 'draft.body_html' => ['nullable','string','max:250000'],
            'draft.body_text' => ['nullable','string','max:100000'],
        ], EmailTemplateDocument::rules('draft.')));
        abort_if(strlen(json_encode($data['draft'])) > 350000, 422, 'The draft is too large.');
        $workspaceKey = $this->workspaceKey($data['template_id'] ?? null);
        // Autosaved drafts may contain unfinished links. Only an explicit save
        // compiles and publishes the email; this private snapshot changes no template.
        $changed = LockedWrite::run(function () use ($data, $draft, $request, $workspaceKey) {
            if ($data['base_revision'] === 0) {
                return DB::table('email_template_drafts')->insertOrIgnore(['id' => $draft, 'user_id' => $request->user()->id, 'template_id' => $data['template_id'] ?? null, 'workspace_key' => $workspaceKey,
                    'revision' => 1, 'draft' => json_encode($data['draft']), 'created_at' => now(), 'updated_at' => now()]);
            }
            return DB::table('email_template_drafts')->where('id', $draft)->where('user_id', $request->user()->id)->where('workspace_key', $workspaceKey)->where('revision', $data['base_revision'])
                ->update(['draft' => json_encode($data['draft']), 'revision' => $data['base_revision'] + 1, 'updated_at' => now()]);
        });
        abort_unless($changed, 409, 'This draft changed in another tab. Reopen its latest version or save your work as a copy.');
        return response()->json(['id' => $draft, 'revision' => $data['base_revision'] + 1]);
    }

    public function destroy(Request $request, string $draft)
    {
        abort_unless(Str::isUuid($draft), 404);
        $data = $request->validate(['base_revision' => ['required','integer','min:1']]);
        $deleted = LockedWrite::run(fn () => DB::table('email_template_drafts')->where('id', $draft)->where('user_id', $request->user()->id)
            ->where('revision', $data['base_revision'])->delete());
        abort_unless($deleted, 409, 'This draft changed in another tab. Reopen its latest version before discarding it.');
        return response()->json(['deleted' => true]);
    }

    private function workspaceKey(?int $templateId): string
    {
        return $templateId === null ? 'new' : 'template:'.$templateId;
    }

    public function revisions(MessageTemplate $template)
    {
        return response()->json(DB::table('email_template_revisions')->where('template_id', $template->id)->latest('id')->limit(30)->get()
            ->map(fn ($row) => ['id' => $row->id, 'created_at' => \Carbon\Carbon::parse($row->created_at)->toIso8601String(), 'draft' => json_decode($row->snapshot, true)]));
    }

    public function sections(Request $request)
    {
        return response()->json(DB::table('email_template_sections')->where('user_id', $request->user()->id)->latest('updated_at')->limit(100)->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'section' => json_decode($row->section, true)]));
    }

    public function saveSection(Request $request)
    {
        $data = $request->validate(['name' => ['required','string','max:150'], 'section' => ['required','array']]);
        \Illuminate\Support\Facades\Validator::make(['editor_document_json' => ['version' => 2, 'format' => 'sections', 'preheader' => '', 'sections' => [$data['section']]]], EmailTemplateDocument::rules())->validate();
        $id = (string) Str::uuid();
        DB::table('email_template_sections')->insert(['id' => $id, 'user_id' => $request->user()->id, 'name' => $data['name'], 'section' => json_encode($data['section']), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id], 201);
    }
}
