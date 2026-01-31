<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\AutomationRule;
use Illuminate\Database\Seeder;

class MessagingSystemSeeder extends Seeder
{
    private const BRAND_NAME = 'REPro Photos';
    private const BRAND_PHONE = '202-868-1663';
    private const BRAND_EMAIL = 'contact@reprophotos.com';
    private const BRAND_SITE = 'https://reprophotos.com';
    private const BRAND_PORTAL = 'https://reprodashboard.com';

    private array $tokenMap = [
        '[greeting]' => '{{greeting}}',
        '[realtor_first]' => '{{client_first_name}}',
        '[realtor_last]' => '{{client_last_name}}',
        '[realtor_company]' => '{{client_company}}',
        '[realtor_email]' => '{{client_email}}',
        '[phone_number]' => '{{client_phone}}',
        '[company_name]' => '{{company_name}}',
        '[company_email]' => '{{company_email}}',
        '[portal_url]' => '{{portal_url}}',
        '[password_resetlink]' => '{{password_reset_link}}',
        '[shoot_location]' => '{{shoot_location}}',
        '[shoot_date]' => '{{shoot_date}}',
        '[shoot_time]' => '{{shoot_time}}',
        '[shoot_packages]' => '{{shoot_packages}}',
        '[shoot_quote]' => '{{shoot_total}}',
        '[shoot_notes]' => '{{shoot_notes}}',
        '[photographer_first]' => '{{photographer_first_name}}',
        '[photographer_last]' => '{{photographer_last_name}}',
        '[photographer_name]' => '{{photographer_name}}',
        '[pay_link]' => '{{payment_link}}',
        '[shoot_completeddate]' => '{{shoot_completed_date}}',
        '[current_date]' => '{{current_date}}',
        '[payment_amount]' => '{{payment_amount}}',
        '[small_zip_link]' => '{{small_zip_link}}',
        '[full_zip_link]' => '{{full_zip_link}}',
        '[mls_tour_link]' => '{{mls_tour_link}}',
        '[branded_tour_link]' => '{{branded_tour_link}}',
        '[changes_made]' => '{{shoot_change_summary}}',
        '[decline_reason]' => '{{decline_reason}}',
        '[photo_count]' => '{{photo_count}}',
        '[download_link]' => '{{download_link}}',
        '[invoice_number]' => '{{invoice_number}}',
        '[amount_due]' => '{{amount_due}}',
        '[due_date]' => '{{due_date}}',
        '[payment_link]' => '{{payment_link}}',
        '[payment_date]' => '{{payment_date}}',
        '[services_provided]' => '{{services_provided}}',
        '[cancellation_reason]' => '{{cancellation_reason}}',
        '[refund_amount]' => '{{refund_amount}}',
        '[original_invoice]' => '{{original_invoice}}',
        '[refund_date]' => '{{refund_date}}',
        '[refund_reason]' => '{{refund_reason}}',
        '[shoot_packages]' => '{{shoot_packages}}',
        '[shoot_duration]' => '{{shoot_duration}}',
        '[shoot_time]' => '{{shoot_time}}',
        '[shoot_address]' => '{{shoot_address}}',
        '[email_signature]' => '{{email_signature}}',
        '[custom_schedulingfields]' => '{{custom_scheduling_fields}}',
        '[misc_link_title]' => '{{misc_link_title}}',
        '[misc_link_url]' => '{{misc_link_url}}',
    ];

    private array $variableMap = [
        'realtor_first' => 'client_first_name',
        'realtor_last' => 'client_last_name',
        'realtor_company' => 'client_company',
        'realtor_email' => 'client_email',
        'phone_number' => 'client_phone',
        'company_name' => 'company_name',
        'company_email' => 'company_email',
        'portal_url' => 'portal_url',
        'password_resetlink' => 'password_reset_link',
        'shoot_location' => 'shoot_location',
        'shoot_date' => 'shoot_date',
        'shoot_time' => 'shoot_time',
        'photographer_first' => 'photographer_first_name',
        'photographer_last' => 'photographer_last_name',
        'photographer_name' => 'photographer_name',
        'shoot_packages' => 'shoot_packages',
        'shoot_quote' => 'shoot_total',
        'shoot_notes' => 'shoot_notes',
        'pay_link' => 'payment_link',
        'shoot_completeddate' => 'shoot_completed_date',
        'current_date' => 'current_date',
        'payment_amount' => 'payment_amount',
        'small_zip_link' => 'small_zip_link',
        'full_zip_link' => 'full_zip_link',
        'mls_tour_link' => 'mls_tour_link',
        'branded_tour_link' => 'branded_tour_link',
        'changes_made' => 'shoot_change_summary',
        'decline_reason' => 'decline_reason',
        'photo_count' => 'photo_count',
        'download_link' => 'download_link',
        'invoice_number' => 'invoice_number',
        'amount_due' => 'amount_due',
        'due_date' => 'due_date',
        'payment_link' => 'payment_link',
        'payment_date' => 'payment_date',
        'services_provided' => 'services_provided',
        'cancellation_reason' => 'cancellation_reason',
        'refund_amount' => 'refund_amount',
        'original_invoice' => 'original_invoice',
        'refund_date' => 'refund_date',
        'refund_reason' => 'refund_reason',
        'shoot_duration' => 'shoot_duration',
        'email_signature' => 'email_signature',
        'custom_schedulingfields' => 'custom_scheduling_fields',
        'misc_link_title' => 'misc_link_title',
        'misc_link_url' => 'misc_link_url',
    ];

    public function run(): void
    {
        $this->seedSystemTemplates();
        $this->seedRequiredAutomations();
    }

    private function seedSystemTemplates(): void
    {
        $templates = [
            // 1. New Account Created
            [
                'channel' => 'EMAIL',
                'name' => 'New Account Created',
                'slug' => 'account-created',
                'description' => '[company_name] New Account Information',
                'category' => 'ACCOUNT',
                'subject' => '[company_name] New Account Information',
                'body_html' => $this->getAccountCreatedTemplate(),
                'body_text' => $this->getAccountCreatedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'realtor_last', 'realtor_company', 'realtor_email', 'phone_number', 'company_name', 'company_email', 'portal_url', 'password_resetlink'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 2. Shoot Scheduled
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Scheduled',
                'slug' => 'shoot-scheduled',
                'description' => 'New Shoot Scheduled for location',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Scheduled for [shoot_location]',
                'body_html' => $this->getShootScheduledTemplate(),
                'body_text' => $this->getShootScheduledPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_quote', 'shoot_notes', 'pay_link', 'company_email', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 3. Shoot Requested
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Requested',
                'slug' => 'shoot-requested',
                'description' => 'New Photo Shoot Requested (PENDING)',
                'category' => 'BOOKING',
                'subject' => 'New Photo Shoot Requested (PENDING) - [shoot_location]',
                'body_html' => $this->getShootRequestedTemplate(),
                'body_text' => $this->getShootRequestedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'shoot_packages', 'shoot_quote', 'shoot_notes', 'company_email', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 4. Shoot Request Approved
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Scheduled (Request Approved)',
                'slug' => 'shoot-request-approved',
                'description' => 'Requested shoot has been approved',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Scheduled (REQUEST APPROVED) - [shoot_location]',
                'body_html' => $this->getShootRequestApprovedTemplate(),
                'body_text' => $this->getShootRequestApprovedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_quote', 'shoot_notes', 'pay_link', 'company_email', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 5. Shoot Request Modified/Verified
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Scheduled (Request Verified/Modified Approved)',
                'slug' => 'shoot-request-modified',
                'description' => 'Request approved with modifications',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Scheduled (REQUEST APPROVED) - [shoot_location]',
                'body_html' => $this->getShootRequestModifiedTemplate(),
                'body_text' => $this->getShootRequestModifiedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_quote', 'shoot_notes', 'pay_link', 'company_email', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 6. Shoot Request Declined
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Scheduled (Request Declined)',
                'slug' => 'shoot-request-declined',
                'description' => 'Requested shoot has been declined',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Request (DECLINED) - [shoot_location]',
                'body_html' => $this->getShootRequestDeclinedTemplate(),
                'body_text' => $this->getShootRequestDeclinedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 7. Shoot Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Reminder',
                'slug' => 'shoot-reminder',
                'description' => 'Upcoming shoot reminder',
                'category' => 'REMINDER',
                'subject' => 'Shoot Reminder - [shoot_location]',
                'body_html' => $this->getShootReminderTemplate(),
                'body_text' => $this->getShootReminderPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 8. Shoot Updated
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Updated',
                'slug' => 'shoot-updated',
                'description' => 'Scheduled shoot has been updated',
                'category' => 'BOOKING',
                'subject' => 'Scheduled Photo Shoot for [shoot_location] Updated',
                'body_html' => $this->getShootUpdatedTemplate(),
                'body_text' => $this->getShootUpdatedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_notes', 'company_email', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 9. Shoot Ready
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Ready',
                'slug' => 'shoot-ready',
                'description' => 'Photos uploaded and ready for client',
                'category' => 'GENERAL',
                'subject' => '[shoot_location] - Photos Ready!',
                'body_html' => $this->getShootReadyTemplate(),
                'body_text' => $this->getShootReadyPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'shoot_packages', 'shoot_quote', 'shoot_notes', 'pay_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 10. Payment Due Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Payment Due Reminder',
                'slug' => 'payment-due-reminder',
                'description' => 'Payment due reminder for completed shoot',
                'category' => 'PAYMENT',
                'subject' => '[shoot_location] - Payment Due Reminder',
                'body_html' => $this->getPaymentDueReminderTemplate(),
                'body_text' => $this->getPaymentDueReminderPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'shoot_quote', 'shoot_completeddate', 'shoot_packages', 'shoot_notes', 'pay_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 11. Thank You For Payment
            [
                'channel' => 'EMAIL',
                'name' => 'Thank You For Your Payment',
                'slug' => 'payment-thank-you',
                'description' => 'Payment received confirmation',
                'category' => 'PAYMENT',
                'subject' => 'Thank You for Your Payment!',
                'body_html' => $this->getPaymentThankYouTemplate(),
                'body_text' => $this->getPaymentThankYouPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'realtor_last', 'shoot_location', 'current_date', 'payment_amount', 'shoot_packages', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 12. Shoot Summary
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Summary',
                'slug' => 'shoot-summary',
                'description' => 'Completed shoot summary with download links',
                'category' => 'GENERAL',
                'subject' => '[shoot_location] - Summary',
                'body_html' => $this->getShootSummaryTemplate(),
                'body_text' => $this->getShootSummaryPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_packages', 'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 13. Shoot Deleted
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Deleted',
                'slug' => 'shoot-deleted',
                'description' => 'Shoot removed from schedule',
                'category' => 'BOOKING',
                'subject' => 'Photo Shoot Removed from Schedule',
                'body_html' => $this->getShootDeletedTemplate(),
                'body_text' => $this->getShootDeletedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_packages', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 14. Refund Submitted
            [
                'channel' => 'EMAIL',
                'name' => 'Refund Submitted',
                'slug' => 'refund-submitted',
                'description' => 'Shoot refund has been applied',
                'category' => 'PAYMENT',
                'subject' => 'Photo Shoot Refund Applied',
                'body_html' => $this->getRefundSubmittedTemplate(),
                'body_text' => $this->getRefundSubmittedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_packages', 'shoot_notes'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 15. Property Contact Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Property Contact Reminder',
                'slug' => 'property-contact-reminder',
                'description' => 'Reminder to provide property contact or lockbox details',
                'category' => 'REMINDER',
                'subject' => 'Action Required: Property Access Details for [shoot_location]',
                'body_html' => $this->getPropertyContactReminderTemplate(),
                'body_text' => $this->getPropertyContactReminderPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 16. Property Contact Reminder SMS
            [
                'channel' => 'SMS',
                'name' => 'Property Contact Reminder SMS',
                'slug' => 'property-contact-reminder-sms',
                'description' => 'SMS reminder to provide property contact or lockbox details',
                'category' => 'REMINDER',
                'subject' => '',
                'body_html' => $this->getPropertyContactReminderSmsTemplate(),
                'body_text' => $this->getPropertyContactReminderSmsTemplate(),
                'variables_json' => ['shoot_location', 'shoot_date', 'shoot_time', 'portal_url'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 17. Photographer Assigned
            [
                'channel' => 'EMAIL',
                'name' => 'Photographer Assigned',
                'slug' => 'photographer-assigned',
                'description' => 'Notify photographer when assigned to a shoot',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Assignment - [shoot_location]',
                'body_html' => $this->getPhotographerAssignedTemplate(),
                'body_text' => $this->getPhotographerAssignedPlainText(),
                'variables_json' => ['greeting', 'photographer_first', 'shoot_location', 'shoot_date', 'shoot_time', 'shoot_packages', 'shoot_notes', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            $normalized = $this->normalizeTemplateDefinition($template);

            MessageTemplate::updateOrCreate(
                ['slug' => $normalized['slug']],
                $normalized
            );
        }
    }

    private function seedRequiredAutomations(): void
    {
        $automations = [
            [
                'name' => 'Send Account Creation Email',
                'description' => 'Automatically send welcome email when account is created',
                'trigger_type' => 'ACCOUNT_CREATED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Shoot Booking Confirmation',
                'description' => 'Confirm booking to client',
                'trigger_type' => 'SHOOT_BOOKED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Reminder',
                'description' => 'Send reminder before shoot',
                'trigger_type' => 'SHOOT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'schedule_json' => ['offset' => '-24h'],
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Payment Confirmation',
                'description' => 'Send receipt when payment is completed',
                'trigger_type' => 'PAYMENT_COMPLETED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Property Contact Reminder - 2 Days Before',
                'description' => 'Remind client to provide property contact or lockbox details (2 days before shoot)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 2],
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Property Contact Reminder - 1 Day Before',
                'description' => 'Remind client to provide property contact or lockbox details (1 day before shoot)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 1],
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Property Contact Reminder - Shoot Day',
                'description' => 'Remind client to provide property contact or lockbox details (on shoot day)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 0],
                'recipients_json' => ['client'],
            ],
            // SMS Reminders
            [
                'name' => 'Property Contact Reminder SMS - 2 Days Before',
                'description' => 'SMS reminder to provide property contact or lockbox details (2 days before shoot)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 2],
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Property Contact Reminder SMS - 1 Day Before',
                'description' => 'SMS reminder to provide property contact or lockbox details (1 day before shoot)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 1],
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Property Contact Reminder SMS - Shoot Day',
                'description' => 'SMS reminder to provide property contact or lockbox details (on shoot day)',
                'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'condition_json' => ['days_before' => 0],
                'recipients_json' => ['client'],
            ],
            // Additional automations
            [
                'name' => 'Shoot Request Received',
                'description' => 'Notify client when shoot request is received',
                'trigger_type' => 'SHOOT_REQUESTED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Request Approved',
                'description' => 'Notify client when shoot request is approved',
                'trigger_type' => 'SHOOT_REQUEST_APPROVED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Request Declined',
                'description' => 'Notify client when shoot request is declined',
                'trigger_type' => 'SHOOT_REQUEST_DECLINED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Updated Notification',
                'description' => 'Notify client when shoot is updated',
                'trigger_type' => 'SHOOT_UPDATED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Photos Ready Notification',
                'description' => 'Notify client when photos are ready',
                'trigger_type' => 'SHOOT_COMPLETED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Cancelled Notification',
                'description' => 'Notify client when shoot is cancelled',
                'trigger_type' => 'SHOOT_CANCELED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Shoot Removed Notification',
                'description' => 'Notify client when shoot is removed',
                'trigger_type' => 'SHOOT_REMOVED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client', 'photographer'],
            ],
            [
                'name' => 'Refund Notification',
                'description' => 'Notify client when refund is processed',
                'trigger_type' => 'PAYMENT_REFUNDED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Photographer Assignment Notification',
                'description' => 'Notify photographer when assigned to a shoot',
                'trigger_type' => 'PHOTOGRAPHER_ASSIGNED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['photographer'],
            ],
        ];

        foreach ($automations as $automation) {
            $slugMap = [
                'ACCOUNT_CREATED' => 'account-created',
                'SHOOT_BOOKED' => 'shoot-scheduled',
                'SHOOT_REMINDER' => 'shoot-reminder',
                'PAYMENT_COMPLETED' => 'payment-thank-you',
                'PROPERTY_CONTACT_REMINDER' => 'property-contact-reminder',
                'SHOOT_REQUESTED' => 'shoot-requested',
                'SHOOT_REQUEST_APPROVED' => 'shoot-request-approved',
                'SHOOT_REQUEST_DECLINED' => 'shoot-request-declined',
                'SHOOT_UPDATED' => 'shoot-updated',
                'SHOOT_COMPLETED' => 'shoot-ready',
                // MEDIA_UPLOAD_COMPLETE removed - Photos Ready should only trigger on SHOOT_COMPLETED (finalize)
                'SHOOT_CANCELED' => 'shoot-deleted',
                'SHOOT_REMOVED' => 'shoot-deleted',
                'PAYMENT_REFUNDED' => 'refund-submitted',
                'PHOTOGRAPHER_ASSIGNED' => 'photographer-assigned',
            ];

            // For property contact reminders, use email template for email channel and SMS template for SMS
            if ($automation['trigger_type'] === 'PROPERTY_CONTACT_REMINDER') {
                // Check if this is an SMS rule (name contains "SMS")
                if (strpos($automation['name'], 'SMS') !== false) {
                    $templateSlug = 'property-contact-reminder-sms';
                } else {
                    $templateSlug = 'property-contact-reminder';
                }
                $automation['template_id'] = MessageTemplate::where('slug', $templateSlug)->first()?->id;
            } elseif (isset($slugMap[$automation['trigger_type']])) {
                $automation['template_id'] = MessageTemplate::where('slug', $slugMap[$automation['trigger_type']])->first()?->id;
            }

            AutomationRule::updateOrCreate(
                ['trigger_type' => $automation['trigger_type'], 'name' => $automation['name']],
                $automation
            );
        }
    }

    // EMAIL WRAPPER
    private function getEmailWrapper($content): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f5f5f5; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #1a1a1a; padding: 30px 40px; text-align: center; }
        .logo-text { font-size: 28px; font-weight: bold; color: #ffffff; letter-spacing: 2px; }
        .content { padding: 40px; color: #333333; line-height: 1.6; }
        .button { display: inline-block; background-color: #000000; color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; text-align: center; }
        .info-box { background-color: #f8f8f8; border-left: 4px solid #000000; padding: 20px; margin: 20px 0; }
        .info-row { padding: 8px 0; }
        .info-label { font-weight: 600; color: #666666; display: inline-block; width: 160px; }
        .footer { background-color: #f8f8f8; padding: 30px 40px; text-align: center; color: #666666; font-size: 13px; line-height: 1.8; }
        .footer-signature { margin: 20px 0; padding: 20px; background-color: #ffffff; border-radius: 6px; }
        h1 { font-size: 24px; margin: 0 0 20px 0; color: #1a1a1a; font-weight: 600; }
        p { margin: 12px 0; }
        ul { margin: 10px 0; padding-left: 20px; }
        .note { background-color: #fff3cd; border-left: 4px solid: #ffc107; padding: 15px; margin: 20px 0; color: #856404; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo-text">' . self::BRAND_NAME . '</div>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <div class="footer-signature">
                <strong>Customer Service Team</strong><br>
                <strong style="font-size: 16px;">' . self::BRAND_NAME . '</strong><br>
                📞 ' . self::BRAND_PHONE . '<br>
                📧 <a href="mailto:' . self::BRAND_EMAIL . '" style="color: #666666;">' . self::BRAND_EMAIL . '</a><br>
                🌐 <a href="' . self::BRAND_SITE . '" style="color: #666666;">' . self::BRAND_SITE . '</a><br>
                📊 Pro Dashboard: <a href="' . self::BRAND_PORTAL . '" style="color: #666666;">' . self::BRAND_PORTAL . '</a>
            </div>
            <p style="margin: 15px 0;">
                We would love your feedback: <a href="#" style="color: #666666; font-weight: 600;">Post a review on Google</a>
            </p>
            <p style="font-size: 11px; color: #999999; margin-top: 20px;">
                © ' . date('Y') . ' ' . self::BRAND_NAME . '. All Rights Reserved.
            </p>
        </div>
    </div>
</body>
</html>';
    }

    // TEMPLATE HTML METHODS

    private function getAccountCreatedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>A new account has been created on the <strong>RE Pro Dashboard</strong>: <a href="https://reprophotos.com">https://reprophotos.com</a></p>
            
            <p>[password_resetlink]</p>
            
            <p>To login to your account, visit <a href="[portal_url]">[portal_url]</a> at any time.</p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>For future reference, the information you have submitted to create your account is listed below:</strong></p>
                <div class="info-row">
                    <span class="info-label">Name:</span> [realtor_first] [realtor_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Company:</span> [realtor_company]
                </div>
                <div class="info-row">
                    <span class="info-label">Phone:</span> [phone_number]
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span> [realtor_email]
                </div>
            </div>
            
            <p>If you have any questions about your account please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p><strong>Thanks for the opportunity to provide you with outstanding real estate marketing services!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootScheduledTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>A new photo shoot has been scheduled under your account!</p>
            
            <p>You can find the shoot listed under <strong>Scheduled Shoots</strong> after logging into <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <center>
                <a href="[pay_link]" class="button">Pay Now</a>
            </center>
            
            <p style="font-size: 13px; color: #666;">Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.</p>
            
            <p>If you have any questions about this photo shoot please feel free to contact us, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p><strong>Thanks for scheduling, we appreciate your business!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>We have received your request for a new photo shoot!</p>
            
            <div class="note">
                <strong>NOTE:</strong> This shoot is in <strong>PENDING</strong> status. You will receive a confirmation email once the shoot has been accepted, along with any updated adjustments we make.
            </div>
            
            <p>You can view your pending shoots at the top of your account via <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was requested:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p><strong>Thanks for requesting a photo shoot, your business is appreciated!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestApprovedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your requested photo shoots has been <strong style="color: #22c55e;">APPROVED</strong> and scheduled under your account! You can find the shoot listed under <strong>Scheduled Shoots</strong> after logging into <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <center>
                <a href="[pay_link]" class="button">Pay Now</a>
            </center>
            
            <p style="font-size: 13px; color: #666;">Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p><strong>Thanks for scheduling, your business is appreciated!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestModifiedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your requested photo shoots has been <strong style="color: #22c55e;">APPROVED</strong> and scheduled under your account! You can find the shoot listed under <strong>Scheduled Shoots</strong> after logging into <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="note">
                <strong>NOTE:</strong> Please review the below shoot information carefully as some details may have changed since your request.
            </div>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <p style="font-size: 13px; color: #666;">Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p><strong>Thanks for scheduling, your business is appreciated!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestDeclinedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>Unfortunately one of your requested shoots has been <strong style="color: #dc2626;">declined</strong>.</p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was declined:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>If you have any questions about this declined request please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootReminderTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>You have a scheduled shoot coming up! Here is a summary of the latest shoot information:</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <p style="font-size: 13px; color: #666;">Don\'t want to receive email reminders? Login to your account, click <strong>My Account</strong>, and turn OFF Email Reminders.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootUpdatedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your scheduled photo shoots has been <strong>updated</strong>. Here is a summary of the latest information regarding the shoot that was updated:</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>Visit <a href="[portal_url]">[portal_url]</a> to manage your shoots.</p>
            
            <p>To ensure a smooth shoot process, please have the property ready. <a href="#">Here is a link to getting your property ready for the shoot</a>.</p>
            
            <div class="note">
                <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
            </div>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootReadyTemplate(): string
    {
        $content = '
            <h1>Your Photos Are Ready! 📸</h1>
            <p>[greeting]!</p>
            
            <p>The content for <strong>[shoot_location]</strong> is uploaded!</p>
            
            <p>You can view the images from the shoot by logging in to your account at <a href="[portal_url]">[portal_url]</a> and clicking on the shoot under <strong>Completed Shoots</strong>. Click on the thumbnail photos to see them larger on your screen.</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <center>
                <a href="[pay_link]" class="button">Pay Now</a>
            </center>
            
            <p style="font-size: 13px; color: #666;">If you have photo packages for download, the download links will be accessible once payment has been received in full. You can make the requested payment by clicking on the red Pay button.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p><strong>We would love your feedback:</strong> <a href="#">Post a review on Google</a>.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getPaymentDueReminderTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>This is a friendly reminder that one of your shoots is ready and <strong>payment is requested</strong>:</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Payment Due:</span> <strong style="font-size: 18px; color: #dc2626;">[shoot_quote]</strong>
                </div>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Completed Date:</span> [shoot_completeddate]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <center>
                <a href="[pay_link]" class="button">Pay Now</a>
            </center>
            
            <p>Alternatively you can make the requested payment by logging in to your account at <a href="[portal_url]">[portal_url]</a> and clicking on the shoot under <strong>Completed Shoots</strong>.</p>
            
            <p>If you have any questions about this notice please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getPaymentThankYouTemplate(): string
    {
        $content = '
            <h1>Payment Received - Thank You! ✓</h1>
            <p>[greeting], [realtor_first] [realtor_last]!</p>
            
            <p>Thank you for paying for your photo shoot!</p>
            
            <div class="info-box" style="background-color: #f0fdf4; border-left-color: #22c55e;">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Date:</span> [current_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Amount:</span> <strong style="font-size: 18px; color: #22c55e;">[payment_amount]</strong>
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>Once your photos are completed you will receive a Summary email if you have photo packages ready for download.</p>
            
            <p>If you have any questions about this photo shoot please reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p><strong>Thank you!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootSummaryTemplate(): string
    {
        $content = '
            <h1>Your Shoot is Ready!</h1>
            <p>[greeting]!</p>
            
            <p>One of your photo shoots is ready!</p>
            
            <p>You can access the content by logging in to your account at <a href="[portal_url]">[portal_url]</a> and clicking on the shoot under <strong>Completed Shoots</strong>.</p>
            
            <p>For your convenience, here is a summary of important files/links regarding your shoot.</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>📥 Download Links:</strong></p>
            
            <div style="margin: 20px 0;">
                <p style="margin: 10px 0;"><strong>Small/MLS-Size Images Download Link</strong></p>
                <p><a href="[small_zip_link]" class="button" style="display: inline-block; padding: 10px 20px; font-size: 14px;">[small_zip_link]</a></p>
                <p style="font-size: 13px; color: #666;">Great for uploading to MLS. Also great for email, Facebook, Twitter, Websites, etc.</p>
            </div>
            
            <div style="margin: 20px 0;">
                <p style="margin: 10px 0;"><strong>Full-Size Images Download Link</strong></p>
                <p><a href="[full_zip_link]" class="button" style="display: inline-block; padding: 10px 20px; font-size: 14px;">[full_zip_link]</a></p>
                <p style="font-size: 13px; color: #666;">Great for print, or if your company system requires full-size photos when uploading listings.</p>
            </div>
            
            <p><strong>🏠 Virtual Tour Links:</strong></p>
            
            <div style="margin: 20px 0;">
                <p style="margin: 10px 0;"><strong>MLS-Compliant Tour Link</strong></p>
                <p><a href="[mls_tour_link]" style="color: #0066cc;">[mls_tour_link]</a></p>
                <p style="font-size: 13px; color: #666;">Non-Branded without your information, approved by MLS.</p>
            </div>
            
            <div style="margin: 20px 0;">
                <p style="margin: 10px 0;"><strong>Branded Tour Link</strong></p>
                <p><a href="[branded_tour_link]" style="color: #0066cc;">[branded_tour_link]</a></p>
                <p style="font-size: 13px; color: #666;">Branded with your information, great for third party websites that allow your information to be displayed within the Tour.</p>
            </div>
            
            <p style="font-size: 13px; color: #666;">You can also access the download links by logging in to your account at <a href="[portal_url]">[portal_url]</a> and clicking on the shoot under Completed Shoots.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p><strong>We would love your feedback:</strong> <a href="#">Post a review on Google</a>.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootDeletedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your Real Estate photo shoots has been <strong>removed from the schedule</strong> due to a cancellation or a re-schedule.</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>If you need real estate photography services for this property in the future please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getRefundSubmittedTemplate(): string
    {
        $content = '
            <h1>Refund Applied</h1>
            <p>One of your Real Estate photo shoots has been <strong>refunded</strong>.</p>
            
            <div class="info-box" style="background-color: #f0f9ff; border-left-color: #3b82f6;">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>If you have any questions regarding this refund, please feel free to reply to this email.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    // PLAIN TEXT VERSIONS
    
    private function getAccountCreatedPlainText(): string
    {
        return '[greeting], [realtor_first]!

A new account has been created on the REPRO HQ client website: https://pro.reprohq.com

[password_resetlink]

To login to your account, visit [portal_url] at any time.

For future reference, the information you have submitted to create your account is listed below:

Name: [realtor_first] [realtor_last]
Company: [realtor_company]
Phone: [phone_number]
Email: [realtor_email]

If you have any questions about your account please feel free to reply to this email, or email [company_email] directly.

Thanks for the opportunity to provide you with outstanding real estate marketing services!

Customer Service Team
REPRO HQ
202-868-1663
contact@reprohq.com
https://reprohq.com';
    }

    private function getShootScheduledPlainText(): string
    {
        return '[greeting], [realtor_first]!

A new photo shoot has been scheduled under your account!

You can find the shoot listed under Scheduled Shoots after logging into [portal_url]

Here is a summary of the shoot that was scheduled:

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]
Shoot total: [shoot_quote]

[shoot_notes]

To ensure a smooth shoot process, please have the property ready.

For your convenience, you can pay without logging in by clicking the following link: [pay_link]

Payment may be made at any time throughout the shoot process.

Our Cancellation Policy: If an appointment is cancelled on-site, a cancellation fee of $60 will be charged.

Thanks for scheduling, we appreciate your business!';
    }

    private function getShootRequestedPlainText(): string
    {
        return '[greeting], [realtor_first]!

We have received your request for a new photo shoot!

NOTE: This shoot is in PENDING status. You will receive a confirmation email once the shoot has been accepted.

Location: [shoot_location]
Requested Shoot Date: [shoot_date]
Requested Shoot Time: [shoot_time]
[shoot_packages]
Total: [shoot_quote]

[shoot_notes]

Thanks for requesting a photo shoot, your business is appreciated!';
    }

    private function getShootRequestApprovedPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your requested photo shoots has been APPROVED and scheduled under your account!

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]
Total: [shoot_quote]

[shoot_notes]

Payment link: [pay_link]

Thanks for scheduling, your business is appreciated!';
    }

    private function getShootRequestModifiedPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your requested photo shoots has been APPROVED and scheduled under your account!

NOTE: Please review the below shoot information carefully as some details may have changed since your request.

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]
Total: [shoot_quote]

[shoot_notes]

Thanks for scheduling, your business is appreciated!';
    }

    private function getShootRequestDeclinedPlainText(): string
    {
        return '[greeting], [realtor_first]!

Unfortunately one of your requested shoots has been declined.

Location: [shoot_location]
Requested Shoot Date: [shoot_date]
Requested Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]

[shoot_notes]

If you have any questions about this declined request please feel free to reply to this email.

Thank you!';
    }

    private function getShootReminderPlainText(): string
    {
        return '[greeting], [realtor_first]!

You have a scheduled shoot coming up!

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]

[shoot_notes]

Our Cancellation Policy: If an appointment is cancelled on-site, a cancellation fee of $60 will be charged.

Thank you!';
    }

    private function getShootUpdatedPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your scheduled photo shoots has been updated.

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[shoot_packages]

[shoot_notes]

Visit [portal_url] to manage your shoots.

Thank you!';
    }

    private function getShootReadyPlainText(): string
    {
        return '[greeting], [realtor_first]!

The content for [shoot_location] is uploaded!

You can view the images by logging in to [portal_url]

Photographer: [photographer_first] [photographer_last]
[shoot_packages]
Total: [shoot_quote]

[shoot_notes]

Payment link: [pay_link]

Thank you!';
    }

    private function getPaymentDueReminderPlainText(): string
    {
        return '[greeting], [realtor_first]!

This is a friendly reminder that one of your shoots is ready and payment is requested:

Payment Due: [shoot_quote]
Location: [shoot_location]
Completed Date: [shoot_completeddate]
[shoot_packages]

[shoot_notes]

Payment link: [pay_link]

Thank you!';
    }

    private function getPaymentThankYouPlainText(): string
    {
        return '[greeting], [realtor_first] [realtor_last]!

Thank you for paying for your photo shoot!

Location: [shoot_location]
Payment Date: [current_date]
Payment Amount: [payment_amount]
[shoot_packages]

[shoot_notes]

Once your photos are completed you will receive a Summary email.

Thank you!';
    }

    private function getShootSummaryPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your photo shoots is ready!

Location: [shoot_location]
[shoot_packages]

Small/MLS-Size Images: [small_zip_link]
Full-Size Images: [full_zip_link]

MLS-Compliant Tour: [mls_tour_link]
Branded Tour: [branded_tour_link]

Thank you!';
    }

    private function getShootDeletedPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your Real Estate photo shoots has been removed from the schedule due to a cancellation or a re-schedule.

Location: [shoot_location]
[shoot_packages]

[shoot_notes]

Thank you!';
    }

    private function getRefundSubmittedPlainText(): string
    {
        return 'One of your Real Estate photo shoots has been refunded.

Location: [shoot_location]
[shoot_packages]

[shoot_notes]

If you have any questions regarding this refund, please feel free to reply to this email.

Thank you!';
    }

    private function getPropertyContactReminderTemplate(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Access Details Required</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h2 style="color: #2c3e50; margin-top: 0;">Action Required: Property Access Details</h2>
    </div>
    
    <p>[greeting]!</p>
    
    <p>We need property access information for your upcoming shoot:</p>
    
    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>Location:</strong> [shoot_location]</p>
        <p style="margin: 5px 0 0 0;"><strong>Date:</strong> [shoot_date]</p>
        <p style="margin: 5px 0 0 0;"><strong>Time:</strong> [shoot_time]</p>
    </div>
    
    <p><strong>Please provide one of the following:</strong></p>
    <ul>
        <li><strong>Who will be at the property?</strong> (Name and phone number of on-site contact)</li>
        <li><strong>Lockbox details:</strong> (Code and location/instructions)</li>
    </ul>
    
    <p>You can update this information by visiting your shoot details:</p>
    <p style="text-align: center; margin: 30px 0;">
        <a href="[portal_url]" style="background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;">Update Property Access Details</a>
    </p>
    
    <p>This information is essential for our photographer to access the property on the scheduled date.</p>
    
    <p>If you have any questions, please reply to this email or contact us at [company_email].</p>
    
    <p>Thank you!</p>
    
    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
    <p style="color: #666; font-size: 12px;">This is an automated reminder. If you have already provided this information, please disregard this message.</p>
</body>
</html>';
    }

    private function getPropertyContactReminderPlainText(): string
    {
        return '[greeting], [realtor_first]!

We need property access information for your upcoming shoot:

Location: [shoot_location]
Date: [shoot_date]
Time: [shoot_time]

Please provide one of the following:
- Who will be at the property? (Name and phone number of on-site contact)
- Lockbox details: (Code and location/instructions)

You can update this information by visiting: [portal_url]

This information is essential for our photographer to access the property on the scheduled date.

If you have any questions, please contact us at [company_email].

Thank you!

---
This is an automated reminder. If you have already provided this information, please disregard this message.';
    }

    private function getPropertyContactReminderSmsTemplate(): string
    {
        return 'REPRO: Action required for shoot at [shoot_location] on [shoot_date] at [shoot_time]. Please provide property access details (who will be at property or lockbox info). Update: [portal_url]';
    }

    private function getPhotographerAssignedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>You have been assigned to a new photo shoot!</p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Shoot Details:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span> [shoot_packages]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>You can view more details and manage this shoot by logging into your dashboard at <a href="[portal_url]">[portal_url]</a></p>
            
            <p>If you have any questions, please email <a href="mailto:[company_email]">[company_email]</a></p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getPhotographerAssignedPlainText(): string
    {
        return 'Hello!

You have been assigned to a new photo shoot!

SHOOT DETAILS:
Location: [shoot_location]
Date: [shoot_date]
Time: [shoot_time]
Services: [shoot_packages]

Notes: [shoot_notes]

You can view more details and manage this shoot by logging into your dashboard at [portal_url]

If you have any questions, please email [company_email]

Thank you!';
    }

    private function normalizeTemplateDefinition(array $template): array
    {
        foreach (['subject', 'body_html', 'body_text', 'description'] as $field) {
            if (!empty($template[$field])) {
                $template[$field] = $this->transformContent($template[$field]);
            }
        }

        if (!empty($template['variables_json']) && is_array($template['variables_json'])) {
            $template['variables_json'] = $this->mapVariables($template['variables_json']);
        }

        return $template;
    }

    private function transformContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        return str_replace(
            array_keys($this->tokenMap),
            array_values($this->tokenMap),
            $content
        );
    }

    private function mapVariables(array $variables): array
    {
        $mapped = array_map(
            fn ($variable) => $this->variableMap[$variable] ?? $variable,
            $variables
        );

        return array_values(array_unique($mapped));
    }
}
