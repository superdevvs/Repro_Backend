<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repair(
            'shoot-request-modified',
            ['023b9f395e3c8320d99010559850685614241235db64f792eaebe7ecb32ad386'],
            [
                '2098671295dcbac76f061125336d9cec494ea5154a19d6fbbfdea7de472cc6fa',
                '24db893687899e38ba567dcd1509c65b94f1d2defbd6c3d2188ffaaf56d1d087',
            ],
            [
                '1457e98991c0336163fb6f660e93437daab9eaaf7e7035bfa1bfc49574422e6e',
                'd87dd133c61c159efc458fd97ad9718996ec69c61488f08262acc56569aa7e9c',
            ],
            [
                'subject' => 'Shoot request updated — [shoot_location]',
                'body_html' => '<p>[greeting],</p><p>Your shoot request was approved with the following changes:</p>[shoot_changes_html]<p><a href="[portal_url]">Review the updated request</a></p>',
                'body_text' => "[greeting],\n\nYour shoot request was approved with the following changes:\n\n[shoot_change_summary]\n\nReview the updated request: [portal_url]",
                'variables_json' => json_encode(['greeting', 'shoot_location', 'shoot_change_summary', 'shoot_changes_html', 'portal_url']),
                'email_type' => 'SHOOT_REQUEST_MODIFIED',
                'override_enabled' => true,
            ]
        );

        $this->repair(
            'shoot-ready',
            ['a6ae648ff39f573cda6d3a23f1e255eaa85fad99a09d906b0ee1944cdf9ecf4b'],
            ['4382fab02c4dae38916af4f8405ef8bade6d63c5fa1cbb7378fe0ae6db8c5742'],
            ['4ec049f584bdc7d2d121468a586d6ac5ef06fcaa0448d3e56f189ef99ea6a6d3'],
            [
                'body_html' => '<p>[greeting],</p><p>Your completed shoot is ready in the dashboard.</p><p><a href="[portal_url]">Open deliverables</a></p>',
                'body_text' => "[greeting],\n\nYour completed shoot is ready in the dashboard.\n\nOpen deliverables: [portal_url]",
                'variables_json' => json_encode(['greeting', 'shoot_location', 'portal_url']),
                'email_type' => 'SHOOT_DELIVERED',
                'override_enabled' => true,
            ]
        );
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring a known unsafe default could
        // reintroduce payment copy for paid or bypassed shoots.
    }

    /**
     * Update only a byte-for-byte known default. Any administrator edit to the
     * subject, HTML, or text changes its hash and is left untouched.
     *
     * @param  array<int, string>  $subjectHashes
     * @param  array<int, string>  $htmlHashes
     * @param  array<int, string>  $textHashes
     * @param  array<string, mixed>  $replacement
     */
    private function repair(
        string $slug,
        array $subjectHashes,
        array $htmlHashes,
        array $textHashes,
        array $replacement
    ): void {
        $template = DB::table('message_templates')->where('slug', $slug)->first();
        if (! $template) {
            return;
        }

        if (
            ! in_array(hash('sha256', (string) $template->subject), $subjectHashes, true)
            || ! in_array(hash('sha256', (string) $template->body_html), $htmlHashes, true)
            || ! in_array(hash('sha256', (string) $template->body_text), $textHashes, true)
        ) {
            return;
        }

        if (! Schema::hasColumn('message_templates', 'email_type')) {
            unset($replacement['email_type']);
        }
        if (! Schema::hasColumn('message_templates', 'override_enabled')) {
            unset($replacement['override_enabled']);
        }

        $replacement['updated_at'] = now();
        DB::table('message_templates')->where('id', $template->id)->update($replacement);
    }
};
