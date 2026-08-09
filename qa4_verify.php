<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MessageTemplate;

$inSet = [
  'account-created','shoot-requested','shoot-request-approved','shoot-request-modified',
  'shoot-request-declined','shoot-scheduled','shoot-updated','shoot-reminder',
  'photographer-assigned','photographer-changed','property-contact-reminder',
  'shoot-ready','shoot-delivered','shoot-summary','payment-due-reminder',
  'payment-thank-you','refund-submitted','shoot-deleted','weekly-invoice-generated',
];

$hrefHash = 0; $missingBrand = []; $missingPhone = []; $nonCanon = [];
foreach ($inSet as $slug) {
  $t = MessageTemplate::where('slug', $slug)->first();
  if (!$t) { echo "MISSING TEMPLATE: $slug\n"; continue; }
  $html = (string) $t->body_html;
  if (str_contains($html, 'href="#"')) $hrefHash++;
  if (!str_contains($html, 'R/E Pro Photos')) $missingBrand[] = $slug;
  if (!str_contains($html, '202-868-1663')) $missingPhone[] = $slug;
  if (str_contains($html, 'R/E Pro Dashboard')) $nonCanon[] = $slug;
}
echo "in-set templates checked: " . count($inSet) . "\n";
echo "href=#-count: $hrefHash\n";
echo "missing brand name: " . (empty($missingBrand) ? 'none' : implode(',', $missingBrand)) . "\n";
echo "missing phone: " . (empty($missingPhone) ? 'none' : implode(',', $missingPhone)) . "\n";
echo "non-canonical brand: " . (empty($nonCanon) ? 'none' : implode(',', $nonCanon)) . "\n";
echo "total system templates: " . MessageTemplate::where('is_system', true)->count() . "\n";
