<?php

namespace App\Services\Messaging;

/** Add operational details to factory copy. Authored templates remain untouched. */
class AutomationTemplateContent
{
    public static function enhance(array $template): array
    {
        $details = match ($template['slug']) {
            'account-created' => ['Next step: sign in and complete your profile.', '<p><a href="{{portal_url}}">Sign in and complete your profile</a>. Support: {{company_email}}</p>'],
            'shoot-requested' => ['Your request has been received and is not yet confirmed.', '<p>Your request has been received and is <strong>not yet confirmed</strong>.</p>'],
            'shoot-scheduled', 'shoot-request-approved', 'shoot-request-modified' => ['Manage this booking: {{dashboard_link}}', '<p><a href="{{dashboard_link}}">View booking, reschedule or cancel</a></p>'],
            'shoot-request-declined' => ['Reason: {{decline_reason}}. Choose another date: {{portal_url}}', '<p>Reason: {{decline_reason}}</p><p><a href="{{portal_url}}">Request another date</a></p>'],
            'photographer-assigned', 'shoot-reminder' => [
                "Map: {{map_link}}\nContact: {{property_contact_name}} {{property_contact_phone}}\nAccess: {{access_instructions}}\nShoot: {{dashboard_link}}",
                '<p><a href="{{map_link}}">View property map</a></p><p>Property contact: {{property_contact_name}} {{property_contact_phone}}</p><p>Access instructions: {{access_instructions}}</p><p><a href="{{dashboard_link}}">View shoot</a></p>',
            ],
            'photographer-changed' => ['{{assignment_message}}', '<p><strong>{{assignment_message}}</strong></p>'],
            'shoot-updated' => ['Manage updated booking: {{dashboard_link}}', '<p><a href="{{dashboard_link}}">Review updated booking</a></p>'],
            'shoot-ready' => ['Payment status: {{payment_status}}. Balance: {{remaining_balance}}. Downloads: {{dashboard_link}}', '<p>Payment status: {{payment_status}}. Remaining balance: {{remaining_balance}}.</p><p><a href="{{dashboard_link}}">Open gallery and downloads</a></p>'],
            'shoot-cancelled', 'shoot-deleted' => ['Cancellation reason: {{cancellation_reason}}. Next steps: {{portal_url}}', '<p>Cancellation reason: {{cancellation_reason}}</p><p><a href="{{portal_url}}">Open dashboard for next steps</a></p>'],
            'payment-thank-you' => [
                "Invoice/reference: {{invoice_number}}\nPayment method: {{payment_method}}\nPayment details: {{payment_items}}\nRemaining balance: {{remaining_balance}}\nReceipt: {{receipt_link}}",
                '<p>Invoice/reference: {{invoice_number}}</p><p>Payment method: {{payment_method}}</p><p>Payment details: {{payment_items}}</p><p>Remaining balance: {{remaining_balance}}</p><p><a href="{{receipt_link}}">View receipt</a></p>',
            ],
            'refund-submitted' => [
                "Refund amount: {{refund_amount}}\nOriginal payment: {{original_payment_reference}}\nRefund method: {{refund_method}}\nSettlement: {{refund_settlement_timing}}",
                '<p>Refund amount: {{refund_amount}}</p><p>Original payment: {{original_payment_reference}}</p><p>Refund method: {{refund_method}}</p><p>{{refund_settlement_timing}}</p>',
            ],
            'property-contact-reminder' => ['{{access_warning}} Update access details: {{dashboard_link}}', '<p><strong>{{access_warning}}</strong></p><p><a href="{{dashboard_link}}">Update access and contact details</a></p>'],
            'property-contact-reminder-sms' => ['{{access_warning}} Update: {{dashboard_link}}', ''],
            default => null,
        };
        if ($details === null) {
            return $template;
        }
        [$text, $html] = $details;
        $template['body_text'] = rtrim($template['body_text'] ?? '')."\n\n".$text;
        $template['body_html'] = rtrim($template['body_html'] ?? '').$html;
        preg_match_all('/{{([a-z_]+)}}/', $text.$html, $matches);
        $template['variables_json'] = array_values(array_unique(array_merge($template['variables_json'] ?? [], $matches[1])));

        return $template;
    }
}
