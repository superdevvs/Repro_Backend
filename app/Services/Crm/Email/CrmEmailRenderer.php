<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmSignature;
use App\Models\User;
use App\Services\SystemEmails\EmailArtwork;
use App\Services\SystemEmails\EmailPresentation;

/**
 * Builds the final email in the locked R/E Pro Photos layout. Only the subject,
 * body, one image and one button come from the template or the user; the logo,
 * colours, fonts, signature block, unsubscribe link and company address are
 * supplied by the shared Dashboard layout and the approved rep signature.
 */
class CrmEmailRenderer
{
    public const MERGE_FIELDS = ['first_name', 'name', 'company', 'market', 'rep_first_name', 'rep_name', 'rep_signature'];

    /**
     * @param  array{subject: string, body_html: string, preheader?: ?string, image_url?: ?string, cta_label?: ?string, cta_url?: ?string}  $content
     * @param  array<string, string>  $vars  merge values
     * @return array{subject: string, html: string, text: string}
     */
    public function render(array $content, array $vars, ?string $signatureHtml, ?string $unsubscribeUrl, bool $isMarketing = true): array
    {
        $subject = $this->merge($content['subject'], $vars, false);
        $body = CrmHtmlSanitizer::clean(str_replace('{{rep_signature}}', '', $this->merge($content['body_html'], $vars, true)));
        $ctaLabel = $this->merge((string) ($content['cta_label'] ?? ''), $vars, false);
        $ctaUrl = $this->merge((string) ($content['cta_url'] ?? ''), $vars, false);
        $hasCta = $ctaLabel !== '' && preg_match('#^(https?://|mailto:)#i', $ctaUrl);
        $imageUrl = (string) ($content['image_url'] ?? '');
        if (str_starts_with($imageUrl, '/storage/')) {
            $imageUrl = rtrim((string) config('app.url'), '/').$imageUrl;
        }
        $address = trim((string) config('crm.email.company_address'));

        $html = view('emails.messaging.atelier', [
            'subject' => $subject,
            'preheaderText' => $this->merge((string) ($content['preheader'] ?? ''), $vars, false),
            'heroTitleHtml' => e($subject),
            'bodyHtml' => view('emails.crm.body', compact('body', 'hasCta', 'ctaLabel', 'ctaUrl', 'signatureHtml'))->render(),
            'branding' => array_filter(['email_logo_grey_url' => config('crm.email.logo_url')]),
            'emailAtelier' => preg_match('#^https?://#i', $imageUrl)
                ? ['kind' => 'illustration', 'asset' => 'crm-template', 'src' => $imageUrl]
                : ($isMarketing ? app(EmailArtwork::class)->resolve('crm_thank_you') : ['kind' => 'none']),
            'emailOutreachFooter' => true,
            'emailPostalAddress' => $address,
            'emailUnsubscribeUrl' => $isMarketing ? $unsubscribeUrl : null,
        ])->render();

        $text = CrmHtmlSanitizer::toText($body);
        if ($hasCta) {
            $text .= "\n\n".$ctaLabel.': '.$ctaUrl;
        }
        if ($signatureHtml) {
            $text .= "\n\n".CrmHtmlSanitizer::toText($signatureHtml);
        }
        $text .= "\n\n--\nR/E Pro Photos".($address !== '' ? ', '.$address : '');
        if ($isMarketing && $unsubscribeUrl) {
            $text .= "\nUnsubscribe: ".$unsubscribeUrl;
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Merge values for a contact and the sending rep. */
    public function varsFor(?CrmContact $contact, ?User $sender, array $extra = []): array
    {
        $name = trim((string) ($contact?->name ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : 'there';
        $repName = trim((string) ($sender?->name ?? ''));

        return array_merge([
            'first_name' => $first,
            'name' => $name,
            'company' => (string) ($contact?->company ?? ''),
            'market' => (string) ($contact?->market_city ?: ($contact?->market_state ?: 'your area')),
            'rep_first_name' => $repName !== '' ? explode(' ', $repName)[0] : 'R/E Pro Photos',
            'rep_name' => $repName,
        ], $extra);
    }

    public function signatureHtml(?CrmSignature $signature): ?string
    {
        if (! $signature) {
            return null;
        }
        $f = $signature->fields ?? [];
        $colors = EmailPresentation::palette();
        $lines = [
            '<strong class="dark-strong" style="font-size:14px;color:'.$colors['ink'].'">'.e($f['name'] ?? '').'</strong>',
            e(trim(($f['title'] ?? '').' · R/E Pro Photos', ' ·')),
            e(trim(($f['phone'] ?? '').' · '.$signature->sender_email, ' ·')),
        ];
        $photo = $signature->headshot_path
            ? '<td style="padding-right:14px;vertical-align:middle"><img src="'.e($this->publicUrl($signature->headshot_path)).'" width="48" height="48" alt="" style="display:block;border-radius:24px;border:0"></td>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;"><tr>'.$photo
            .'<td class="dark-muted" style="border-left:2px solid '.$colors['accent'].';padding-left:14px;font-size:13px;line-height:19px;color:'.$colors['muted'].'">'.implode('<br>', $lines).'</td></tr></table>';
    }

    private function publicUrl(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim((string) config('app.url'), '/').'/storage/'.ltrim($path, '/');
    }

    private function merge(string $text, array $vars, bool $escape): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, $escape) {
            if ($m[1] === 'rep_signature') {
                return '{{rep_signature}}';
            }
            $value = (string) ($vars[$m[1]] ?? '');

            return $escape ? e($value) : $value;
        }, $text) ?? $text;
    }
}
