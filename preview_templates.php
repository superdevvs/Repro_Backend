<?php

/**
 * One-off preview generator for QA issue #11.
 *
 * Boots Laravel, loads every seeded MessageTemplate (email + SMS), renders each
 * through the real TemplateRenderer with a rich sample dataset, and writes a
 * single standalone gallery HTML to the Desktop so all templates can be reviewed
 * for content, variables, branding, contact details, consistency and correctness.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Support\Arr;

/** @var TemplateRenderer $renderer */
$renderer = app(TemplateRenderer::class);

// ---------------------------------------------------------------------------
// Rich, human-readable sample data so each preview renders fully populated.
// We bypass TemplateVariableResolver (which needs real Shoot/Invoice models)
// and feed the renderer a complete sample variable map directly.
// ---------------------------------------------------------------------------
$sample = [
    'greeting'                 => 'Hi Jordan',
    'recipient_name'           => 'Jordan Smith',
    'recipient_first_name'     => 'Jordan',
    'recipient_email'          => 'jordan.smith@example.com',
    'client_name'              => 'Jordan Smith',
    'client_first_name'        => 'Jordan',
    'client_last_name'         => 'Smith',
    'client_company'           => 'Smith Realty Group',
    'client_email'             => 'jordan.smith@example.com',
    'client_phone'             => '(202) 555-0184',
    'realtor_first'            => 'Jordan',
    'realtor_last'             => 'Smith',
    'realtor_company'          => 'Smith Realty Group',
    'realtor_email'            => 'jordan.smith@example.com',
    'phone_number'             => '(202) 555-0184',

    'company_name'             => 'R/E Pro Photos',
    'company_email'            => 'contact@reprophotos.com',
    'company_phone'            => '(202) 868-1663',
    'company_address'          => 'Washington, DC Metro Area',
    'portal_url'               => 'https://reprodashboard.com',
    'current_date'             => date('M j, Y'),
    'email_signature'          => 'R/E Pro Photos',

    'shoot_id'                 => '10482',
    'shoot_location'           => '4821 Brandywine St NW, Washington, DC 20016',
    'shoot_address'            => '4821 Brandywine St NW, Washington, DC 20016',
    'shoot_date'               => date('M j, Y', strtotime('+3 days')),
    'shoot_time'               => '10:30 AM',
    'shoot_packages'           => "- Premium Photo Package\n- Aerial Drone Add-on",
    'services_provided'        => "- Premium Photo Package - \$249.00\n- Aerial Drone Add-on - \$120.00",
    'services_provided_html'   => '<ul style="margin:0;padding-left:18px;">'
        . '<li style="margin:0 0 8px 0;">Premium Photo Package <strong>$249.00</strong></li>'
        . '<li style="margin:0 0 8px 0;">Aerial Drone Add-on <strong>$120.00</strong></li>'
        . '</ul>',
    'assigned_photographers'   => 'Alex Rivera',
    'photographer_name'        => 'Alex Rivera',
    'photographer_first_name'  => 'Alex',
    'photographer_email'       => 'alex.rivera@reprophotos.com',
    'photographer_phone'       => '202-555-0142',
    'previous_photographer_name' => 'Sam Carter',
    'new_photographer_name'    => 'Alex Rivera',
    'shoot_total'              => '369.00',
    'shoot_quote'              => '$369.00',
    'shoot_notes'              => 'Front gate code is 4821. Please photograph the rear garden last.',
    'shoot_completed_date'     => date('M j, Y'),
    'photo_count'              => '48',
    'mls_tour_link'            => 'https://reprodashboard.com/tour/10482',
    'pay_link'                 => 'https://reprodashboard.com/pay/10482',
    'payment_link'             => 'https://reprodashboard.com/pay/10482',
    'small_zip_link'           => 'https://reprodashboard.com/download/10482/web',
    'full_zip_link'            => 'https://reprodashboard.com/download/10482/full',
    'cancellation_reason'      => 'Seller requested a new date.',
    'decline_reason'           => 'Requested time slot is unavailable.',

    'shoot_changes'            => "Scheduled Date: May 12, 2026 -> May 15, 2026\nScheduled Time: 9:00 AM -> 10:30 AM",
    'shoot_changes_html'       => '',
    'shoot_change_summary'     => "Scheduled Date: May 12, 2026 -> May 15, 2026",
    'photographer_change_summary' => 'Photographer reassigned from Sam Carter to Alex Rivera.',

    'invoice_number'           => 'INV-2026-0482',
    'invoice_total'            => '$369.00',
    'invoice_amount'           => '$369.00',
    'amount'                   => '$369.00',
    'amount_due'               => '$369.00',
    'invoice_due_date'         => date('M j, Y', strtotime('+14 days')),
    'due_date'                 => date('M j, Y', strtotime('+14 days')),
    'invoice_link'             => 'https://reprodashboard.com/invoices/INV-2026-0482',
    'invoice_url'              => 'https://reprodashboard.com/invoices/INV-2026-0482',
    'refund_amount'            => '$120.00',
    'payment_amount'           => '$369.00',
    'payment_date'             => date('M j, Y'),
    'week_range'               => date('M j', strtotime('-7 days')) . ' - ' . date('M j, Y'),

    // Shared snippet tokens (canonical wording is supplied by the seeder/resolver;
    // provide readable sample copy so the preview is complete).
    'property_prep_html'       => '<p>To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.</p>',
    'property_prep_text'       => 'To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.',
    'cancellation_policy_html' => '<div class="note"><strong>Cancellation Policy</strong><br>If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.</div>',
    'cancellation_policy_text' => 'Cancellation policy: If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.',
    'recipient_booking_intro'  => 'A new photo shoot has been scheduled under your account.',
    'recipient_update_intro'   => 'One of your scheduled photo shoots has been updated. Please review the latest details below.',
    'recipient_manage_copy'    => 'You can find the shoot in your dashboard under <strong>Scheduled Shoots</strong> after logging into <a href="https://reprodashboard.com">https://reprodashboard.com</a>.',
    'recipient_manage_copy_text' => 'You can find the shoot in your dashboard under Scheduled Shoots after logging into https://reprodashboard.com.',
    'payment_cta_html'         => '<div style="margin:24px 0;"><a class="button" href="https://reprodashboard.com/pay/10482">Pay Now</a></div>',
    'payment_cta_text'         => 'Payment link: https://reprodashboard.com/pay/10482',
];

// Collect every placeholder token used across all templates so we can fill any
// that the explicit sample map missed with a visible, labelled fallback.
$templates = MessageTemplate::orderBy('id')->get();

$allKeys = [];
foreach ($templates as $t) {
    foreach ((array) ($t->variables_json ?? []) as $k) {
        $allKeys[$k] = true;
    }
    $blob = ($t->subject ?? '') . ' ' . ($t->body_html ?? '') . ' ' . ($t->body_text ?? '');
    if (preg_match_all('/\[([a-zA-Z0-9_]+)\]/', $blob, $m)) {
        foreach ($m[1] as $k) {
            $allKeys[$k] = true;
        }
    }
    if (preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $blob, $m)) {
        foreach ($m[1] as $k) {
            $allKeys[$k] = true;
        }
    }
}
foreach (array_keys($allKeys) as $k) {
    if (!array_key_exists($k, $sample)) {
        $sample[$k] = '[' . $k . ']'; // visible so reviewers can spot unmapped tokens
    }
}

// ---------------------------------------------------------------------------
// Render each template and build the gallery.
// ---------------------------------------------------------------------------
$cards = [];
$navItems = [];
$emailCount = 0;
$smsCount = 0;

foreach ($templates as $i => $t) {
    $result = $renderer->render($t, $sample);
    $isEmail = $t->channel === 'EMAIL';
    $isEmail ? $emailCount++ : $smsCount++;

    $anchor = 'tpl-' . $t->id;
    $subject = trim((string) ($result['subject'] ?? ''));
    $html = (string) ($result['html'] ?? '');
    $text = (string) ($result['text'] ?? '');
    $missing = (array) ($result['missing'] ?? []);

    $navItems[] = sprintf(
        '<a href="#%s"><span class="badge %s">%s</span>%s</a>',
        $anchor,
        $isEmail ? 'badge-email' : 'badge-sms',
        $isEmail ? 'EMAIL' : 'SMS',
        htmlspecialchars($t->name ?? $t->slug, ENT_QUOTES)
    );

    $previewBlock = $isEmail
        ? sprintf(
            '<iframe class="email-frame" srcdoc="%s" loading="lazy"></iframe>',
            htmlspecialchars($html, ENT_QUOTES)
        )
        : sprintf(
            '<div class="sms-bubble">%s</div>',
            nl2br(htmlspecialchars($text !== '' ? $text : strip_tags($html)))
        );

    $textBlock = sprintf(
        '<details class="plain"><summary>Plain-text version</summary><pre>%s</pre></details>',
        htmlspecialchars($text, ENT_QUOTES)
    );

    $missingBlock = $missing
        ? '<div class="missing">Unresolved variables in sample render: <code>'
            . htmlspecialchars(implode(', ', $missing), ENT_QUOTES) . '</code></div>'
        : '';

    $badgeClass = $isEmail ? 'badge-email' : 'badge-sms';
    $channelLabel = $isEmail ? 'EMAIL' : 'SMS';
    $subjectEsc = htmlspecialchars($subject !== '' ? $subject : '(none)', ENT_QUOTES);
    $num = $i + 1;
    $nameEsc = htmlspecialchars((string) $t->name, ENT_QUOTES);
    $slugEsc = htmlspecialchars((string) $t->slug, ENT_QUOTES);
    $catEsc = htmlspecialchars((string) $t->category, ENT_QUOTES);

    $cards[] = <<<CARD
<section class="card" id="{$anchor}">
  <header class="card-head">
    <div>
      <h2>{$num}. {$nameEsc}</h2>
      <div class="meta">
        <span class="badge {$badgeClass}">{$channelLabel}</span>
        <code>slug: {$slugEsc}</code>
        <code>category: {$catEsc}</code>
      </div>
      <div class="subject"><strong>Subject:</strong> {$subjectEsc}</div>
    </div>
  </header>
  {$missingBlock}
  <div class="preview">{$previewBlock}</div>
  {$textBlock}
</section>
CARD;
}

$generatedAt = date('Y-m-d H:i');
$total = $templates->count();
$nav = implode("\n", $navItems);
$body = implode("\n", $cards);

$page = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>R/E Pro Photos - All Message Templates Preview (QA #11)</title>
<style>
  :root { --bg:#0f172a; --panel:#ffffff; --muted:#64748b; --line:#e2e8f0; --accent:#1463ff; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; background:#f1f5f9; color:#0f172a; }
  .topbar { background:var(--bg); color:#fff; padding:20px 28px; position:sticky; top:0; z-index:10; }
  .topbar h1 { margin:0 0 4px; font-size:20px; }
  .topbar p { margin:0; color:#94a3b8; font-size:13px; }
  .layout { display:grid; grid-template-columns:280px 1fr; gap:0; align-items:start; }
  .sidebar { position:sticky; top:84px; max-height:calc(100vh - 84px); overflow:auto; padding:18px; border-right:1px solid var(--line); background:#fff; }
  .sidebar a { display:flex; align-items:center; gap:8px; padding:7px 8px; border-radius:8px; color:#0f172a; text-decoration:none; font-size:13px; }
  .sidebar a:hover { background:#eef2ff; }
  .content { padding:24px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:14px; margin:0 0 26px; padding:18px 20px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
  .card-head h2 { margin:0 0 8px; font-size:17px; }
  .meta { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px; }
  .meta code, .subject code { background:#f1f5f9; padding:2px 7px; border-radius:6px; font-size:12px; color:#334155; }
  .subject { font-size:13px; color:#334155; margin-bottom:6px; }
  .badge { font-size:10px; font-weight:800; letter-spacing:.5px; padding:3px 8px; border-radius:999px; color:#fff; }
  .badge-email { background:#1463ff; }
  .badge-sms { background:#16a34a; }
  .preview { margin-top:12px; }
  .email-frame { width:100%; height:760px; border:1px solid var(--line); border-radius:10px; background:#fff; }
  .sms-bubble { max-width:380px; background:#e7f8ec; border:1px solid #b9e6c6; border-radius:16px; padding:14px 16px; font-size:14px; line-height:1.5; white-space:normal; }
  .plain { margin-top:12px; }
  .plain summary { cursor:pointer; font-size:13px; color:var(--accent); }
  .plain pre { white-space:pre-wrap; background:#0f172a; color:#e2e8f0; padding:14px; border-radius:10px; font-size:12px; overflow:auto; }
  .missing { margin-top:10px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:8px 12px; border-radius:8px; font-size:12px; }
  .missing code { background:transparent; }
</style>
</head>
<body>
  <div class="topbar">
    <h1>R/E Pro Photos &mdash; All Message Templates Preview</h1>
    <p>QA issue #11 &middot; {$total} templates ({$emailCount} email, {$smsCount} SMS) &middot; rendered via the live TemplateRenderer with sample data &middot; generated {$generatedAt}</p>
  </div>
  <div class="layout">
    <nav class="sidebar">{$nav}</nav>
    <main class="content">{$body}</main>
  </div>
</body>
</html>
HTML;

// Resolve the Windows Desktop path.
$desktop = getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\Desktop' : __DIR__;
$outPath = $desktop . '\\RE-Pro-Photos-Email-Templates-Preview.html';
file_put_contents($outPath, $page);

echo "Rendered {$total} templates ({$emailCount} email, {$smsCount} SMS)\n";
echo "Preview written to: {$outPath}\n";
