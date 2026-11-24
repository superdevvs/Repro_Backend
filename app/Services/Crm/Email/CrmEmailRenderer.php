<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmSignature;
use App\Models\User;

/**
 * Builds the final email in the locked R/E Pro Photos layout. Only the subject,
 * body, one image and one button come from the template or the user; the logo,
 * colours, fonts, signature block, unsubscribe link and company address are
 * fixed here and cannot be edited.
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
        $body = $this->merge(CrmHtmlSanitizer::clean($content['body_html']), $vars, true);
        $body = str_replace('{{rep_signature}}', '', $body);

        $image = ! empty($content['image_url']) ? '<tr><td style="padding:0 32px 20px"><img src="'.e($content['image_url']).'" alt="" width="536" style="display:block;width:100%;max-width:536px;border-radius:8px;border:0"></td></tr>' : '';
        $cta = ! empty($content['cta_label']) && ! empty($content['cta_url'])
            ? '<tr><td style="padding:4px 32px 24px"><a href="'.e($this->merge($content['cta_url'], $vars, false)).'" style="display:inline-block;background:#0080ff;color:#ffffff;font-weight:600;font-size:15px;text-decoration:none;padding:11px 20px;border-radius:8px">'.e($content['cta_label']).'</a></td></tr>'
            : '';
        $signature = $signatureHtml ? '<tr><td style="padding:0 32px 28px">'.$signatureHtml.'</td></tr>' : '';
        $preheader = ! empty($content['preheader']) ? '<div style="display:none;max-height:0;overflow:hidden">'.e($this->merge($content['preheader'], $vars, false)).'</div>' : '';
        $address = trim((string) config('crm.email.company_address'));
        $footerLines = ['R/E Pro Photos'.($address !== '' ? ' · '.e($address) : '')];
        if ($isMarketing && $unsubscribeUrl) {
            $footerLines[] = 'You are receiving this because you work with or asked about R/E Pro Photos. <a href="'.e($unsubscribeUrl).'" style="color:#55657a">Unsubscribe</a>';
        }
        $logo = e((string) config('crm.email.logo_url', 'https://reprodashboard.com/images/repro-email-logo-light.png'));

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.e($subject).'</title></head>'
            .'<body style="margin:0;padding:0;background:#f3f5f7;font-family:Inter,Segoe UI,Arial,sans-serif;color:#0f1a26">'.$preheader
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f7"><tr><td align="center" style="padding:24px 12px">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e2e8ee;border-radius:12px">'
            .'<tr><td style="padding:24px 32px 8px"><img src="'.$logo.'" alt="R/E Pro Photos" height="28" style="display:block;height:28px;border:0"></td></tr>'
            .'<tr><td style="padding:16px 32px 8px;font-size:15px;line-height:24px">'.$body.'</td></tr>'
            .$image.$cta.$signature
            .'</table>'
            .'<p style="max-width:600px;margin:16px auto 0;font-size:12px;line-height:18px;color:#55657a;text-align:center">'.implode('<br>', $footerLines).'</p>'
            .'</td></tr></table></body></html>';

        $text = CrmHtmlSanitizer::toText($body);
        if (! empty($content['cta_label']) && ! empty($content['cta_url'])) {
            $text .= "\n\n".$content['cta_label'].': '.$this->merge($content['cta_url'], $vars, false);
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
        $lines = [
            '<strong style="font-size:14px">'.e($f['name'] ?? '').'</strong>',
            e(trim(($f['title'] ?? '').' · R/E Pro Photos', ' ·')),
            e(trim(($f['phone'] ?? '').' · '.$signature->sender_email, ' ·')),
        ];
        $photo = $signature->headshot_path
            ? '<td style="padding-right:14px;vertical-align:middle"><img src="'.e($this->publicUrl($signature->headshot_path)).'" width="48" height="48" alt="" style="display:block;border-radius:24px;border:0"></td>'
            : '';

        return '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'.$photo
            .'<td style="border-left:2px solid #0080ff;padding-left:14px;font-size:13px;line-height:19px;color:#3a4a5c">'.implode('<br>', $lines).'</td></tr></table>';
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
