<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getSchemaBuilder()->hasTable('message_templates')) {
            DB::table('message_templates')
                ->where('slug', 'property-contact-reminder')
                ->update([
                    'body_html' => $this->reminderHtml(),
                    'updated_at' => now(),
                ]);
        }

        if (! DB::getSchemaBuilder()->hasTable('automation_rules')) {
            return;
        }

        foreach (DB::table('automation_rules')->where('trigger_type', 'PROPERTY_CONTACT_REMINDER')->orderBy('id')->get() as $rule) {
                if (str_contains((string) $rule->name, 'SMS')) {
                    return;
                }

                $workflow = json_decode((string) $rule->workflow_definition_json, true);
                if (is_array($workflow['nodes'] ?? null)) {
                    foreach ($workflow['nodes'] as &$node) {
                        if (($node['type'] ?? '') !== 'action.email') {
                            continue;
                        }
                        $node['config']['recipientMode'] = 'roles';
                        $node['config']['recipientRoles'] = ['client', 'rep'];
                    }
                    unset($node);
                }

                DB::table('automation_rules')->where('id', $rule->id)->update([
                    'recipients_json' => json_encode(['client', 'rep']),
                    'workflow_definition_json' => is_array($workflow) ? json_encode($workflow) : $rule->workflow_definition_json,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // The previous recipient list emailed every admin. Leave the corrected list in place.
    }

    private function reminderHtml(): string
    {
        return '
    <p>[greeting]!</p>
    <p>We need property access information for your upcoming shoot:</p>
    <div class="info-box">
        <div class="info-row"><span class="info-label">Location</span><span class="info-value">[shoot_location]</span></div>
        <div class="info-row"><span class="info-label">Date</span><span class="info-value">[shoot_date]</span></div>
        <div class="info-row"><span class="info-label">Time</span><span class="info-value">[shoot_time]</span></div>
    </div>
    <p><strong>Please provide one of the following:</strong></p>
    <ul>
        <li><strong>Who will be at the property?</strong> (Name and phone number of on-site contact)</li>
        <li><strong>Lockbox details:</strong> (Code and location/instructions)</li>
    </ul>
    <p>You can update this information by visiting your shoot details:</p>
    <p><a class="button" href="[portal_url]">Update Property Access Details</a></p>
    <p>This information is essential for our photographer to access the property on the scheduled date.</p>
    <p class="note">This is an automated reminder. If you have already provided this information, please disregard this message.</p>';
    }
};
