<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEmailTemplate;
use App\Services\Crm\Email\CrmContentCheck;
use App\Services\Crm\Email\CrmEmailPresenter;
use App\Services\Crm\Email\CrmEmailRenderer;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmHtmlSanitizer;
use App\Services\Crm\Email\CrmSendBlocked;
use App\Services\Crm\Email\CrmSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One-off emails to a contact from an approved template. Unchanged templates
 * send straight away; edited text must pass the content check first.
 */
class CrmEmailController extends CrmController
{
    public function __construct(
        private readonly CrmEmailSender $sender,
        private readonly CrmEmailPresenter $present,
        private readonly CrmContentCheck $check,
    ) {}

    public function sends(Request $request, CrmContact $contact): JsonResponse
    {
        $items = CrmEmailSend::query()->where('contact_id', $contact->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $items->map(fn ($s) => $this->present->send($s))->all()]);
    }

    public function showSend(Request $request, CrmContact $contact, CrmEmailSend $send): JsonResponse
    {
        abort_unless((int) $send->contact_id === (int) $contact->id, 404);

        return response()->json(['send' => $this->present->send($send, true)]);
    }

    /** What the composer needs: approved templates, whether the viewer may send, and why not. */
    public function composeOptions(Request $request, CrmContact $contact): JsonResponse
    {
        $user = $this->actor($request);
        $blocker = app(CrmSignatureService::class)->sendBlocker($user->id);
        if (! $blocker) {
            try {
                $this->sender->assertSendable((string) $contact->email, $contact, $user);
            } catch (CrmSendBlocked $e) {
                $blocker = $e->getMessage();
            }
        }
        $templates = CrmEmailTemplate::query()->whereIn('kind', ['one_off', 'campaign'])->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->orderBy('name')->get();

        return response()->json([
            'blocker' => $blocker,
            'signature' => $this->present->signature(app(CrmSignatureService::class)->currentApproved($user->id)),
            'templates' => $templates->map(fn ($t) => $this->present->template($t))->all(),
            'transport' => $this->sender->transport()->name(),
        ]);
    }

    private function contentFor(Request $request): array
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_html' => ['nullable', 'string', 'max:50000'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        $template = CrmEmailTemplate::query()->whereKey($data['template_id'])->whereNotNull('approved_version_id')->where('status', '!=', 'retired')->firstOrFail();
        $version = $template->approvedVersion;

        $subject = trim((string) ($data['subject'] ?? '')) ?: $version->subject;
        $body = isset($data['body_html']) && trim($data['body_html']) !== '' ? CrmHtmlSanitizer::clean($data['body_html']) : $version->body_html;
        $edited = $subject !== $version->subject || trim($body) !== trim($version->body_html);

        return [
            'version' => $version,
            'edited' => $edited,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'content' => [
                'subject' => $subject, 'body_html' => $body, 'preheader' => $version->preheader,
                'image_url' => $version->image_url, 'cta_label' => $version->cta_label, 'cta_url' => $version->cta_url,
            ],
        ];
    }

    public function preview(Request $request, CrmContact $contact): JsonResponse
    {
        $input = $this->contentFor($request);
        $user = $this->actor($request);
        $renderer = app(CrmEmailRenderer::class);
        $signature = app(CrmSignatureService::class)->currentApproved($user->id);
        $rendered = $renderer->render($input['content'], $renderer->varsFor($contact, $user), $renderer->signatureHtml($signature), '#unsubscribe');
        $check = $input['edited'] ? $this->check->check($input['content']['subject']."\n".CrmHtmlSanitizer::toText($input['content']['body_html']), $user) : null;

        return response()->json($rendered + ['edited' => $input['edited'], 'content_check' => $check]);
    }

    public function send(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $input = $this->contentFor($request);
        $user = $this->actor($request);

        if ($input['edited']) {
            $check = $this->check->check($input['content']['subject']."\n".CrmHtmlSanitizer::toText($input['content']['body_html']), $user);
            if (! $check['passed']) {
                return response()->json(['message' => 'Fix the flagged wording before sending.', 'content_check' => $check], 422);
            }
        }

        try {
            $send = $this->sender->send([
                'contact' => $contact,
                'sender' => $user,
                'source_type' => 'one_off',
                'template_version' => $input['version'],
                'content' => $input['content'],
                'idempotency_key' => $input['idempotency_key'] ? 'one-off-'.$user->id.'-'.$input['idempotency_key'] : null,
                'marketing' => true,
                'counts_as_contact' => true,
            ]);
        } catch (CrmSendBlocked $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['send' => $this->present->send($send)], 201);
    }
}
