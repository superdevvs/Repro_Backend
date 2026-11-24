<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmEmailTemplate;
use App\Models\Crm\CrmEmailTemplateVersion;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branded templates with versioned content. Saving creates a new version;
 * submitting asks an approver; emails keep using the approved version until a
 * newer one is approved.
 */
class CrmTemplateService
{
    public function __construct(private readonly CrmAccess $access, private readonly CrmContentCheck $check) {}

    public function create(string $name, string $kind, array $content, User $actor): CrmEmailTemplate
    {
        if (! in_array($kind, CrmEmailTemplate::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a template type.']);
        }

        return DB::transaction(function () use ($name, $kind, $content, $actor) {
            $template = CrmEmailTemplate::create(['name' => $name, 'kind' => $kind, 'status' => 'draft', 'owner_id' => $actor->id]);
            $this->saveVersion($template, $content, $actor);

            return $template->fresh();
        });
    }

    /** Save new content. Editing never changes the approved version emails are using. */
    public function saveVersion(CrmEmailTemplate $template, array $content, User $actor): CrmEmailTemplateVersion
    {
        if (trim((string) ($content['subject'] ?? '')) === '' || trim(strip_tags((string) ($content['body_html'] ?? ''))) === '') {
            throw ValidationException::withMessages(['body_html' => 'A template needs a subject and some body text.']);
        }
        $latest = $template->latestVersion;
        $data = [
            'subject' => trim($content['subject']),
            'preheader' => $content['preheader'] ?? null,
            'body_html' => CrmHtmlSanitizer::clean($content['body_html']),
            'image_url' => $content['image_url'] ?? null,
            'cta_label' => $content['cta_label'] ?? null,
            'cta_url' => $content['cta_url'] ?? null,
        ];

        if ($latest && $latest->status === 'draft') {
            $latest->forceFill($data)->save();
            $version = $latest;
        } else {
            $version = CrmEmailTemplateVersion::create($data + [
                'template_id' => $template->id,
                'version_no' => (int) ($template->versions()->max('version_no') ?? 0) + 1,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
        }
        $template->forceFill(['latest_version_id' => $version->id, 'status' => $template->approved_version_id ? 'approved' : 'draft'])->save();

        return $version;
    }

    public function submit(CrmEmailTemplate $template, User $actor): CrmEmailTemplateVersion
    {
        $version = $template->latestVersion;
        if (! $version || $version->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'There are no draft changes to submit.']);
        }
        $result = $this->check->check($version->subject."\n".CrmHtmlSanitizer::toText($version->body_html), $actor);
        $version->forceFill(['status' => 'pending', 'content_check' => $result])->save();
        $template->forceFill(['status' => $template->approved_version_id ? 'approved' : 'pending'])->save();

        return $version;
    }

    public function approve(CrmEmailTemplateVersion $version, User $reviewer): CrmEmailTemplateVersion
    {
        $this->assertApprover($reviewer);
        if ($version->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only submitted versions can be approved.']);
        }
        DB::transaction(function () use ($version, $reviewer) {
            $version->forceFill(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => null])->save();
            $version->template->forceFill(['approved_version_id' => $version->id, 'status' => 'approved'])->save();
        });

        return $version;
    }

    public function reject(CrmEmailTemplateVersion $version, User $reviewer, string $comment): CrmEmailTemplateVersion
    {
        $this->assertApprover($reviewer);
        if ($version->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only submitted versions can be sent back.']);
        }
        $version->forceFill(['status' => 'draft', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment])->save();

        return $version;
    }

    public function retire(CrmEmailTemplate $template, User $actor): void
    {
        $this->assertApprover($actor);
        $template->forceFill(['status' => 'retired'])->save();
    }

    private function assertApprover(User $user): void
    {
        if (! $this->access->can($user, 'crm-templates', 'approve')) {
            throw new AuthorizationException('Only an admin can approve templates.');
        }
    }
}
