<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEmailTemplate;
use App\Models\Crm\CrmEmailTemplateVersion;
use App\Models\Crm\CrmSignature;
use App\Services\Crm\CrmPresenter;

class CrmEmailPresenter
{
    public function __construct(private readonly CrmPresenter $presenter) {}

    public function signature(?CrmSignature $s): ?array
    {
        if (! $s) {
            return null;
        }

        return [
            'id' => $s->id,
            'version' => $s->version,
            'user' => $this->presenter->user($s->user_id),
            'fields' => $s->fields ?? [],
            'sender_email' => $s->sender_email,
            'headshot_url' => $s->headshot_path ? (str_starts_with($s->headshot_path, 'http') ? $s->headshot_path : '/storage/'.$s->headshot_path) : null,
            'status' => $s->status,
            'review_comment' => $s->review_comment,
            'reviewed_by' => $this->presenter->user($s->reviewed_by),
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'is_current_approved' => (bool) $s->is_current_approved,
            'submitted_at' => $s->created_at?->toIso8601String(),
            'html' => app(CrmEmailRenderer::class)->signatureHtml($s),
        ];
    }

    public function version(?CrmEmailTemplateVersion $v): ?array
    {
        if (! $v) {
            return null;
        }

        return [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'subject' => $v->subject,
            'preheader' => $v->preheader,
            'body_html' => $v->body_html,
            'image_url' => $v->image_url,
            'cta_label' => $v->cta_label,
            'cta_url' => $v->cta_url,
            'status' => $v->status,
            'created_by' => $this->presenter->user($v->created_by),
            'reviewed_by' => $this->presenter->user($v->reviewed_by),
            'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'review_comment' => $v->review_comment,
            'content_check' => $v->content_check,
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }

    public function template(CrmEmailTemplate $t, bool $withVersions = false): array
    {
        $out = [
            'id' => $t->id,
            'name' => $t->name,
            'kind' => $t->kind,
            'status' => $t->status,
            'owner' => $this->presenter->user($t->owner_id),
            'approved' => $this->version($t->approvedVersion),
            'latest' => $this->version($t->latestVersion),
            'has_pending_changes' => $t->latest_version_id !== null && $t->latest_version_id !== $t->approved_version_id,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
        if ($withVersions) {
            $out['versions'] = $t->versions()->get()->map(fn ($v) => $this->version($v))->all();
            $out['usage'] = app(\App\Services\Crm\Email\CrmTemplateUsage::class)->for($t);
        }

        return $out;
    }

    public function send(CrmEmailSend $s, bool $withContent = false): array
    {
        $out = [
            'id' => $s->id,
            'source_type' => $s->source_type,
            'source_id' => $s->source_id,
            'from' => $s->from_email,
            'from_name' => $s->from_name,
            'to' => $s->to_email,
            'reply_to' => $s->reply_to,
            'subject' => $s->subject,
            'status' => $s->status,
            'delivered' => $s->status === 'sent' || $s->delivered_at !== null,
            'recorded_only' => $s->status === 'logged',
            'error' => $s->error,
            'sender' => $this->presenter->user($s->sender_user_id),
            'sent_at' => $s->sent_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'opened' => $s->open_count > 0,
            'open_count' => $s->open_count,
            'click_count' => $s->click_count,
            'first_opened_at' => $s->first_opened_at?->toIso8601String(),
            'bounced_at' => $s->bounced_at?->toIso8601String(),
            'replied_at' => $s->replied_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
        if ($withContent) {
            $out['html'] = $s->html;
            $out['text'] = $s->text;
        }

        return $out;
    }
}
