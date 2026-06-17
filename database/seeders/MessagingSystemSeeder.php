<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowConverter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class MessagingSystemSeeder extends Seeder
{
    private const BRAND_NAME = 'R/E Pro Photos';
    private const BRAND_PHONE = '202-868-1113';
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
        '[shoot_changes_html]' => '{{shoot_changes_html}}',
        '[decline_reason]' => '{{decline_reason}}',
        '[photo_count]' => '{{photo_count}}',
        '[download_link]' => '{{download_link}}',
        '[invoice_number]' => '{{invoice_number}}',
        '[amount_due]' => '{{amount_due}}',
        '[due_date]' => '{{due_date}}',
        '[payment_link]' => '{{payment_link}}',
        '[payment_details]' => '{{payment_details}}',
        '[payment_date]' => '{{payment_date}}',
        '[services_provided]' => '{{services_provided}}',
        '[assigned_photographers]' => '{{assigned_photographers}}',
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
        '[services_provided_html]' => '{{services_provided_html}}',
        '[recipient_booking_intro]' => '{{recipient_booking_intro}}',
        '[recipient_update_intro]' => '{{recipient_update_intro}}',
        '[recipient_manage_copy]' => '{{recipient_manage_copy}}',
        '[recipient_manage_copy_text]' => '{{recipient_manage_copy_text}}',
        '[payment_cta_html]' => '{{payment_cta_html}}',
        '[payment_cta_text]' => '{{payment_cta_text}}',
        '[property_prep_html]' => '{{property_prep_html}}',
        '[property_prep_text]' => '{{property_prep_text}}',
        '[cancellation_policy_html]' => '{{cancellation_policy_html}}',
        '[cancellation_policy_text]' => '{{cancellation_policy_text}}',
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
        'shoot_changes_html' => 'shoot_changes_html',
        'decline_reason' => 'decline_reason',
        'photo_count' => 'photo_count',
        'download_link' => 'download_link',
        'invoice_number' => 'invoice_number',
        'amount_due' => 'amount_due',
        'due_date' => 'due_date',
        'payment_link' => 'payment_link',
        'payment_date' => 'payment_date',
        'client_name' => 'client_name',
        'services_provided' => 'services_provided',
        'assigned_photographers' => 'assigned_photographers',
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
        'services_provided_html' => 'services_provided_html',
        'recipient_booking_intro' => 'recipient_booking_intro',
        'recipient_update_intro' => 'recipient_update_intro',
        'recipient_manage_copy' => 'recipient_manage_copy',
        'recipient_manage_copy_text' => 'recipient_manage_copy_text',
        'payment_cta_html' => 'payment_cta_html',
        'payment_cta_text' => 'payment_cta_text',
        'property_prep_html' => 'property_prep_html',
        'property_prep_text' => 'property_prep_text',
        'cancellation_policy_html' => 'cancellation_policy_html',
        'cancellation_policy_text' => 'cancellation_policy_text',
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
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_quote', 'shoot_notes', 'company_email', 'portal_url', 'recipient_booking_intro', 'recipient_manage_copy', 'payment_cta_html', 'property_prep_html', 'cancellation_policy_html'],
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
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_quote', 'shoot_notes', 'company_email', 'portal_url'],
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
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_quote', 'shoot_notes', 'pay_link', 'company_email', 'portal_url', 'shoot_change_summary', 'shoot_changes_html'],
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
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_quote', 'shoot_notes', 'pay_link', 'company_email', 'portal_url', 'shoot_change_summary', 'shoot_changes_html'],
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
                'variables_json' => ['greeting', 'realtor_first', 'decline_reason', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email'],
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
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email'],
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
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'company_email', 'portal_url', 'recipient_update_intro', 'recipient_manage_copy', 'shoot_change_summary', 'shoot_changes_html', 'property_prep_html', 'cancellation_policy_html'],
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
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'shoot_date', 'shoot_time', 'photographer_first', 'photographer_last', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_quote', 'shoot_notes', 'pay_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 10. Shoot Delivered
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Delivered',
                'slug' => 'shoot-delivered',
                'description' => 'Final media delivery with direct access links',
                'category' => 'GENERAL',
                'subject' => '[shoot_location] - Shoot Delivered',
                'body_html' => $this->getShootDeliveredTemplate(),
                'body_text' => $this->getShootDeliveredPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'assigned_photographers', 'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 11. Payment Due Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Weekly Invoice Generated',
                'slug' => 'weekly-invoice-generated',
                'description' => 'Editable weekly invoice notification sent when a new invoice is generated',
                'category' => 'INVOICE',
                'subject' => '{{billing_period}} - Weekly Invoice',
                'body_html' => $this->getWeeklyInvoiceGeneratedTemplate(),
                'body_text' => $this->getWeeklyInvoiceGeneratedPlainText(),
                'variables_json' => ['recipient_name', 'recipient_role', 'billing_period', 'invoice_number', 'invoice_status', 'invoice_total', 'invoice_items_html', 'invoice_items_text', 'dashboard_url', 'invoice_next_step', 'approval_note'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 12. Invoice Payment Reminder
            [
                'channel' => 'EMAIL',
                'name' => 'Invoice Payment Reminder',
                'slug' => 'payment-due-reminder',
                'description' => 'Invoice payment reminder for pending balances',
                'category' => 'INVOICE',
                'subject' => 'Payment Reminder - Invoice [invoice_number]',
                'body_html' => $this->getPaymentDueReminderTemplate(),
                'body_text' => $this->getPaymentDueReminderPlainText(),
                'variables_json' => ['greeting', 'client_first_name', 'client_name', 'company_email', 'invoice_number', 'amount_due', 'due_date', 'payment_link'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 13. Thank You For Payment
            [
                'channel' => 'EMAIL',
                'name' => 'Thank You For Your Payment',
                'slug' => 'payment-thank-you',
                'description' => 'Payment received confirmation',
                'category' => 'PAYMENT',
                'subject' => 'Thank You for Your Payment!',
                'body_html' => $this->getPaymentThankYouTemplate(),
                'body_text' => $this->getPaymentThankYouPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'realtor_last', 'shoot_location', 'current_date', 'payment_amount', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 14. Shoot Summary
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Summary',
                'slug' => 'shoot-summary',
                'description' => 'Completed shoot summary with download links',
                'category' => 'GENERAL',
                'subject' => '[shoot_location] - Summary',
                'body_html' => $this->getShootSummaryTemplate(),
                'body_text' => $this->getShootSummaryPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'small_zip_link', 'full_zip_link', 'mls_tour_link', 'branded_tour_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 15. Shoot Deleted
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Deleted',
                'slug' => 'shoot-deleted',
                'description' => 'Shoot removed from schedule',
                'category' => 'BOOKING',
                'subject' => 'Photo Shoot Removed from Schedule',
                'body_html' => $this->getShootDeletedTemplate(),
                'body_text' => $this->getShootDeletedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 16. Refund Submitted
            [
                'channel' => 'EMAIL',
                'name' => 'Refund Submitted',
                'slug' => 'refund-submitted',
                'description' => 'Shoot refund has been applied',
                'category' => 'PAYMENT',
                'subject' => 'Photo Shoot Refund Applied',
                'body_html' => $this->getRefundSubmittedTemplate(),
                'body_text' => $this->getRefundSubmittedPlainText(),
                'variables_json' => ['greeting', 'realtor_first', 'shoot_location', 'services_provided', 'services_provided_html', 'assigned_photographers', 'shoot_notes'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            
            // 17. Property Contact Reminder
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
            
            // 18. Property Contact Reminder SMS
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
            
            // 19. Photographer Assigned
            [
                'channel' => 'EMAIL',
                'name' => 'Photographer Assigned',
                'slug' => 'photographer-assigned',
                'description' => 'Notify photographer when assigned to a shoot',
                'category' => 'BOOKING',
                'subject' => 'New Shoot Assignment - [shoot_location]',
                'body_html' => $this->getPhotographerAssignedTemplate(),
                'body_text' => $this->getPhotographerAssignedPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
            [
                'channel' => 'EMAIL',
                'name' => 'Photographer Changed',
                'slug' => 'photographer-changed',
                'description' => 'Notify affected photographers when a shoot assignment changes',
                'category' => 'BOOKING',
                'subject' => 'Photographer Assignment Updated - [shoot_location]',
                'body_html' => $this->getPhotographerChangedTemplate(),
                'body_text' => $this->getPhotographerChangedPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email', 'previous_photographer_name', 'new_photographer_name', 'shoot_change_summary'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 21. Shoot On Hold (manual notification)
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot On Hold',
                'slug' => 'shoot-on-hold',
                'description' => 'Photo shoot has been placed on hold',
                'category' => 'BOOKING',
                'subject' => 'Photo Shoot Placed On Hold - [shoot_location]',
                'body_html' => $this->getShootOnHoldTemplate(),
                'body_text' => $this->getShootOnHoldPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 22. Shoot Cancelled (manual notification)
            [
                'channel' => 'EMAIL',
                'name' => 'Shoot Cancelled',
                'slug' => 'shoot-cancelled',
                'description' => 'Photo shoot has been cancelled',
                'category' => 'BOOKING',
                'subject' => 'Photo Shoot Cancelled - [shoot_location]',
                'body_html' => $this->getShootCancelledTemplate(),
                'body_text' => $this->getShootCancelledPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_date', 'shoot_time', 'assigned_photographers', 'services_provided', 'services_provided_html', 'shoot_notes', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 23. Payment Due (manual notification with payment link)
            [
                'channel' => 'EMAIL',
                'name' => 'Payment Due',
                'slug' => 'payment-due',
                'description' => 'Outstanding balance due with a secure payment link',
                'category' => 'PAYMENT',
                'subject' => 'Payment Due - [shoot_location]',
                'body_html' => $this->getPaymentDueTemplate(),
                'body_text' => $this->getPaymentDuePlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'shoot_quote', 'pay_link', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],

            // 24. Payment Receipt (manual notification with payment details)
            [
                'channel' => 'EMAIL',
                'name' => 'Payment Receipt',
                'slug' => 'payment-receipt',
                'description' => 'Payment confirmation receipt with payment details',
                'category' => 'PAYMENT',
                'subject' => 'Payment Receipt - [shoot_location]',
                'body_html' => $this->getPaymentReceiptTemplate(),
                'body_text' => $this->getPaymentReceiptPlainText(),
                'variables_json' => ['greeting', 'shoot_location', 'payment_details', 'portal_url', 'company_email'],
                'scope' => 'SYSTEM',
                'is_system' => true,
                'is_active' => true,
            ],
        ];

        $canonicalSlugs = collect($templates)
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();

        foreach ($templates as $template) {
            $normalized = $this->normalizeTemplateDefinition($template);

            MessageTemplate::updateOrCreate(
                ['slug' => $normalized['slug']],
                $normalized
            );
        }

        MessageTemplate::query()
            ->where('is_system', true)
            ->whereNotNull('slug')
            ->whereNotIn('slug', $canonicalSlugs)
            ->delete();
    }

    private function seedRequiredAutomations(): void
    {
        $workflowConverter = app(AutomationWorkflowConverter::class);
        $supportsVisualWorkflow = collect([
            'editor_mode',
            'engine_version',
            'is_system_locked',
            'workflow_definition_json',
            'entry_trigger_json',
        ])->every(fn (string $column) => Schema::hasColumn('automation_rules', $column));

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
                'recipients_json' => ['photographer'],
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
                'name' => 'Invoice Due Reminder',
                'description' => 'Send invoice reminder when a balance is due',
                'trigger_type' => 'INVOICE_DUE',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'template_id' => MessageTemplate::where('slug', 'payment-due-reminder')->first()?->id,
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Invoice Overdue Reminder',
                'description' => 'Send invoice reminder when a balance remains overdue',
                'trigger_type' => 'INVOICE_OVERDUE',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'template_id' => MessageTemplate::where('slug', 'payment-due-reminder')->first()?->id,
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
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Shoot Request Approved',
                'description' => 'Notify client when shoot request is approved',
                'trigger_type' => 'SHOOT_REQUEST_APPROVED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Shoot Request Modified',
                'description' => 'Notify client when a shoot request is approved with modifications',
                'trigger_type' => 'SHOOT_REQUEST_MODIFIED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
            ],
            [
                'name' => 'Shoot Request Declined',
                'description' => 'Notify client when shoot request is declined',
                'trigger_type' => 'SHOOT_REQUEST_DECLINED',
                'is_active' => true,
                'scope' => 'SYSTEM',
                'recipients_json' => ['client'],
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
                'recipients_json' => ['client'],
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
            [
                'name' => 'Photographer Change Notification',
                'description' => 'Notify affected photographers when a shoot assignment changes',
                'trigger_type' => 'PHOTOGRAPHER_CHANGED',
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
                'SHOOT_REQUEST_MODIFIED' => 'shoot-request-modified',
                'SHOOT_REQUEST_DECLINED' => 'shoot-request-declined',
                'SHOOT_UPDATED' => 'shoot-updated',
                'SHOOT_COMPLETED' => 'shoot-ready',
                // MEDIA_UPLOAD_COMPLETE removed - Photos Ready should only trigger on SHOOT_COMPLETED (finalize)
                'SHOOT_CANCELED' => 'shoot-deleted',
                'SHOOT_REMOVED' => 'shoot-deleted',
                'PAYMENT_REFUNDED' => 'refund-submitted',
                'PHOTOGRAPHER_ASSIGNED' => 'photographer-assigned',
                'PHOTOGRAPHER_CHANGED' => 'photographer-changed',
                'INVOICE_DUE' => 'payment-due-reminder',
                'INVOICE_OVERDUE' => 'payment-due-reminder',
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

            $automationRule = AutomationRule::updateOrCreate(
                ['trigger_type' => $automation['trigger_type'], 'name' => $automation['name']],
                $automation
            );

            if ($supportsVisualWorkflow) {
                $workflow = $workflowConverter->buildLegacyWorkflow($automationRule);
                $triggerNode = collect($workflow['nodes'] ?? [])
                    ->first(fn (array $node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'));

                $automationRule->forceFill([
                    'editor_mode' => 'visual',
                    'engine_version' => 2,
                    'is_system_locked' => $automationRule->scope === 'SYSTEM',
                    'workflow_definition_json' => $workflow,
                    'entry_trigger_json' => [
                        'trigger_type' => $automationRule->trigger_type,
                        'node_id' => $triggerNode['id'] ?? null,
                        'node_type' => $triggerNode['type'] ?? null,
                        'config' => $triggerNode['config'] ?? [],
                    ],
                ])->save();
            }
        }
    }

    // EMAIL BODY NORMALIZER
    //
    // Shared header/footer wrapper. Promotes the previous no-op trim() into the
    // single source of truth for brand chrome around every email body:
    //   - a brand HEADER naming the canonical brand (self::BRAND_NAME),
    //   - the per-template message ($content, trimmed and unchanged),
    //   - a brand FOOTER carrying the canonical sign-off, the support/contact
    //     line, and a signature built from the BRAND_* constants and tokens.
    //
    // The modern renderer owns email chrome. Keep seeded template bodies as
    // content fragments so legacy headers/footers do not get nested in emails.
    private function getEmailWrapper($content): string
    {
        return trim((string) $content);
    }

    // ------------------------------------------------------------------
    // Canonical shared-snippet providers (single source of truth)
    //
    // These methods return the ONE canonical wording per shared concept, in
    // both channels (HTML + text). They exist so that templates which used to
    // hardcode the copy inline can be switched to the SAME canonical wording
    // (applying the token-vs-inlined-snippet choice uniformly across the set),
    // and so the wrapper footer + the standalone documents speak with one
    // voice.
    //
    // The cancellation-policy and property-prep wording is intentionally kept
    // IDENTICAL to the runtime token-backed values resolved by
    // TemplateVariableResolver for the [cancellation_policy_html]/
    // [property_prep_html] (and *_text) tokens, so that whether a template
    // renders the token or the inlined snippet, the same policy reads
    // identically everywhere (2.6). The existing tokens remain valid and stay
    // where already used; these providers supply the matching canonical copy
    // for the previously-hardcoded sites.
    //
    // Tokens are kept in [bracket] form (e.g. [company_email]); generator
    // output is later transformed via transformContent().
    // ------------------------------------------------------------------

    // Cancellation policy - canonical HTML wording (mirrors the token-backed
    // cancellation_policy_html value).
    private function getCancellationPolicyHtml(): string
    {
        return '<div style="margin-top:20px;padding:16px 18px;border:1px solid #fde68a;background:#fffbeb;border-radius:14px;"><strong style="display:block;color:#92400e;margin-bottom:6px;">Cancellation Policy</strong><span style="color:#92400e;">If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.</span></div>';
    }

    // Cancellation policy - canonical plain-text wording (mirrors the
    // token-backed cancellation_policy_text value).
    private function getCancellationPolicyText(): string
    {
        return 'Cancellation policy: If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.';
    }

    // Property-preparation guidance - canonical HTML wording (mirrors the
    // token-backed property_prep_html value; no placeholder link).
    private function getPropertyPrepHtml(): string
    {
        return '<p>To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.</p>';
    }

    // Property-preparation guidance - canonical plain-text wording (mirrors the
    // token-backed property_prep_text value).
    private function getPropertyPrepText(): string
    {
        return 'To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.';
    }

    // Brand contact line - canonical HTML wording (the support/help line shared
    // by the wrapper footer). Keeps [company_email] as a token.
    private function getContactLineHtml(): string
    {
        return '<p style="margin: 0 0 8px 0;">If you need help, call ' . self::BRAND_PHONE . ' or email us at [company_email].</p>';
    }

    // Brand contact line - canonical plain-text wording.
    private function getContactLineText(): string
    {
        return 'If you need help, call ' . self::BRAND_PHONE . ' or email us at [company_email].';
    }

    // Closing sign-off + signature - canonical HTML wording (the closing voice
    // shared by the wrapper footer).
    private function getSignOffHtml(): string
    {
        $brand = self::BRAND_NAME;
        $phone = self::BRAND_PHONE;
        $siteDisplay = preg_replace('#^https?://#', '', self::BRAND_SITE);

        return '<p style="margin: 0;">
                    Thanks,<br>
                    <strong>' . $brand . '</strong><br>
                    ' . $phone . '<br>
                    ' . $siteDisplay . '
                </p>';
    }

    // Closing sign-off + signature - canonical plain-text wording.
    private function getSignOffText(): string
    {
        $siteDisplay = preg_replace('#^https?://#', '', self::BRAND_SITE);

        return "Thanks,\n"
            . self::BRAND_NAME . "\n"
            . self::BRAND_PHONE . "\n"
            . $siteDisplay;
    }

    // TEMPLATE HTML METHODS

    private function getAccountCreatedTemplate(): string
    {
        // Brand name comes from the canonical constant so the HTML agrees with
        // getAccountCreatedPlainText() ("R/E Pro Photos client portal") instead
        // of the old non-canonical "R/E Pro Dashboard". The portal link uses the
        // [portal_url] token rather than a hardcoded https://reprophotos.com URL.
        // Shared contact and sign-off chrome is supplied by the renderer, so
        // the seeded body stays focused on account-specific content.
        $content = '
            <p>[greeting]!</p>
            
            <p>A new account has been created for you on the <strong>' . self::BRAND_NAME . '</strong> client portal: <a href="[portal_url]">[portal_url]</a></p>
            
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
            
            <p>If you have any questions about your account please feel free to reply to this email.</p>

            <p>Thank you for the opportunity.</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootScheduledTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>[recipient_booking_intro]</p>
            
            <p>[recipient_manage_copy]</p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographers:</span> [assigned_photographers]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>

            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            [property_prep_html]
            
            [payment_cta_html]
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            [cancellation_policy_html]
            
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
                    <span class="info-label">Requested Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            ' . $this->getPropertyPrepHtml() . '
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            ' . $this->getCancellationPolicyHtml() . '
            
            <p><strong>Thanks for requesting a photo shoot, your business is appreciated!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestApprovedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your requested photo shoots has been <strong style="color: #1463ff;">APPROVED</strong> and scheduled under your account! You can find the shoot listed under <strong>Scheduled Shoots</strong> after logging into <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>

            <p><strong>Updated Details:</strong></p>
            <p>[shoot_changes_html]</p>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            ' . $this->getPropertyPrepHtml() . '
            
            <center>
                <a href="[pay_link]" class="button">Pay Now</a>
            </center>
            
            <p style="font-size: 13px; color: #666;">Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            ' . $this->getCancellationPolicyHtml() . '
            
            <p><strong>Thanks for scheduling, your business is appreciated!</strong></p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootRequestModifiedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>One of your requested photo shoots has been <strong style="color: #1463ff;">APPROVED</strong> and scheduled under your account! You can find the shoot listed under <strong>Scheduled Shoots</strong> after logging into <a href="[portal_url]">[portal_url]</a></p>
            
            <div class="note">
                <strong>NOTE:</strong> Please review the below shoot information carefully as some details may have changed since your request.
            </div>
            
            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that was scheduled:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>

            <p><strong>Updated Details:</strong></p>
            <p>[shoot_changes_html]</p>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            ' . $this->getPropertyPrepHtml() . '
            
            <p style="font-size: 13px; color: #666;">Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            ' . $this->getCancellationPolicyHtml() . '
            
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
                    <span class="info-label">Decline Reason:</span> [decline_reason]
                </div>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Requested Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
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
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographer:</span> [photographer_first] [photographer_last]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            ' . $this->getPropertyPrepHtml() . '
            
            <p style="font-size: 13px; color: #666;">Don\'t want to receive email reminders? Login to your account, click <strong>My Account</strong>, and turn OFF Email Reminders.</p>
            
            ' . $this->getCancellationPolicyHtml() . '
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootUpdatedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>
            
            <p>[recipient_update_intro]</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographers:</span> [assigned_photographers]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <div class="change-card">
                <div class="change-card-title">Updated Details</div>
                [shoot_changes_html]
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>[recipient_manage_copy]</p>
            
            [property_prep_html]
            
            [cancellation_policy_html]
            
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
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <center>
                <a href="[pay_link]" class="button button-large">Pay Now</a>
            </center>
            
            <p style="font-size: 13px; color: #666;">If you have photo packages for download, the download links will be accessible once payment has been received in full. Use the large Pay Now button above to unlock access as soon as payment is received.</p>
            
            <p>If you have any questions about this photo shoot please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p><strong>We would love your feedback:</strong> if you have a moment, a quick review on Google really helps us out.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootDeliveredTemplate(): string
    {
        $content = '
            <h1>Your Shoot Has Been Delivered</h1>
            <p>[greeting]!</p>

            <p>Your final media for <strong>[shoot_location]</strong> has been delivered and is ready to review.</p>

            <p>You can access everything by logging in to your account at <a href="[portal_url]">[portal_url]</a> and opening the shoot under <strong>Completed Shoots</strong>.</p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <div class="change-card">
                <div class="change-card-title">Delivery Links</div>
                <p style="margin-top: 0;"><strong>Small / MLS Images:</strong> <a href="[small_zip_link]">[small_zip_link]</a></p>
                <p><strong>Full Resolution Images:</strong> <a href="[full_zip_link]">[full_zip_link]</a></p>
                <p><strong>MLS Tour:</strong> <a href="[mls_tour_link]">[mls_tour_link]</a></p>
                <p style="margin-bottom: 0;"><strong>Branded Tour:</strong> <a href="[branded_tour_link]">[branded_tour_link]</a></p>
            </div>

            <center>
                <a href="[portal_url]" class="button button-large">Open Deliverables</a>
            </center>

            <p>If you have any questions about the delivered media, please reply to this email or contact <a href="mailto:[company_email]">[company_email]</a>.</p>

            <p>Thank you!</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getPaymentDueReminderTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>

            <p>This is a reminder that your invoice still has an outstanding balance.</p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Invoice Number:</span> [invoice_number]
                </div>
                <div class="info-row">
                    <span class="info-label">Amount Due:</span> <strong style="font-size: 18px; color: #dc2626;">$[amount_due]</strong>
                </div>
                <div class="info-row">
                    <span class="info-label">Due Date:</span> [due_date]
                </div>
            </div>

            <p>Please use the payment link below to complete the balance.</p>

            <center>
                <a href="[payment_link]" class="button button-large">Pay Now</a>
            </center>

            <p>If you have already paid this invoice, please disregard this notice. If you need help, reply to this email or contact <a href="mailto:[company_email]">[company_email]</a>.</p>

            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getWeeklyInvoiceGeneratedTemplate(): string
    {
        $content = '
            <p>Hello {{recipient_name}}, <strong>your weekly {{recipient_role}} invoice has been generated.</strong></p>

            <center>
                <a href="{{dashboard_url}}" class="button button-large">Review Weekly Invoice</a>
            </center>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Billing Period:</span> {{billing_period}}
                </div>
                <div class="info-row">
                    <span class="info-label">Invoice Number:</span> {{invoice_number}}
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span> {{invoice_status}}
                </div>
                <div class="info-row">
                    <span class="info-label">Total:</span> <strong>{{invoice_total}}</strong>
                </div>
            </div>

            <div class="change-card">
                <div class="change-card-title">Included This Week</div>
                {{invoice_items_html}}
            </div>

            <p>{{invoice_next_step}}</p>

            <p>If something needs attention, open <a href="{{dashboard_url}}">{{dashboard_url}}</a> to review the invoice and follow up before approval moves forward.</p>

            <p>{{approval_note}}</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getPaymentThankYouTemplate(): string
    {
        $content = '
            <h1>Payment Received - Thank You! ✓</h1>
            <p>[greeting], [realtor_first] [realtor_last]!</p>
            
            <p>Thank you for paying for your photo shoot!</p>
            
            <div class="info-box" style="background-color: #eff6ff; border-left-color: #1463ff;">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Date:</span> [current_date]
                </div>
                <div class="info-row">
                <span class="info-label">Payment Amount:</span> <strong style="font-size: 18px; color: #1463ff;">[payment_amount]</strong>
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
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
                    <span class="info-label">Services:</span><br>[services_provided_html]
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
            
            <p><strong>We would love your feedback:</strong> if you have a moment, a quick review on Google really helps us out.</p>
            
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
                    <span class="info-label">Services:</span><br>[services_provided_html]
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
            <p>[greeting]!</p>
            
            <p>One of your Real Estate photo shoots has been <strong>refunded</strong>.</p>
            
            <div class="info-box" style="background-color: #f0f9ff; border-left-color: #3b82f6;">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>
            
            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>
            
            <p>If you have any questions regarding this refund, please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>
            
            <p>Thank you!</p>
        ';
        
        return $this->getEmailWrapper($content);
    }

    private function getShootOnHoldTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>

            <p>One of your photo shoots has been <strong>placed on hold</strong>. We will be in touch to confirm next steps, and the shoot will resume once the hold is cleared.</p>

            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the shoot that is on hold:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographers:</span> [assigned_photographers]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>

            <p>You can review this shoot at any time by logging into <a href="[portal_url]">[portal_url]</a>.</p>

            <p>If you have any questions about this hold please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>

            <p>Thank you!</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getShootCancelledTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>

            <p>One of your photo shoots has been <strong>cancelled</strong>.</p>

            <div class="info-box">
                <p style="margin-top: 0;"><strong>Here is a summary of the cancelled shoot:</strong></p>
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Date:</span> [shoot_date]
                </div>
                <div class="info-row">
                    <span class="info-label">Scheduled Shoot Time:</span> [shoot_time]
                </div>
                <div class="info-row">
                    <span class="info-label">Photographers:</span> [assigned_photographers]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>

            <p>If you need real estate photography services for this property in the future please feel free to reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly. You can also rebook at any time via <a href="[portal_url]">[portal_url]</a>.</p>

            <p>Thank you!</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getPaymentDueTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>

            <p>This is a friendly reminder that a balance is due for your photo shoot at <strong>[shoot_location]</strong>.</p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Shoot total:</span> <strong>[shoot_quote]</strong>
                </div>
            </div>

            <p>Please use the secure payment link below to complete your payment.</p>

            <center>
                <a href="[pay_link]" class="button button-large">Pay Now</a>
            </center>

            <p style="font-size: 13px; color: #666;">If the button does not work, copy and paste this link into your browser: <a href="[pay_link]">[pay_link]</a></p>

            <p>If you have already paid, please disregard this notice. If you have any questions please reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>

            <p>Thank you!</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getPaymentReceiptTemplate(): string
    {
        $content = '
            <h1>Payment Received - Thank You! ✓</h1>
            <p>[greeting]!</p>

            <p>Thank you for your payment for your photo shoot at <strong>[shoot_location]</strong>. Here are your payment details for your records:</p>

            <div class="info-box" style="background-color: #eff6ff; border-left-color: #1463ff;">
                <div class="info-row">
                    <span class="info-label">Location:</span> [shoot_location]
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Details:</span><br><span style="white-space: pre-line;">[payment_details]</span>
                </div>
            </div>

            <p>You can review your shoot and payment history at any time by logging into <a href="[portal_url]">[portal_url]</a>.</p>

            <p>If you have any questions about this payment please reply to this email, or email <a href="mailto:[company_email]">[company_email]</a> directly.</p>

            <p><strong>Thank you!</strong></p>
        ';

        return $this->getEmailWrapper($content);
    }

    // PLAIN TEXT VERSIONS
    
    private function getAccountCreatedPlainText(): string
    {
        return '[greeting], [realtor_first]!

A new account has been created for you on the R/E Pro Photos client portal: [portal_url]

[password_resetlink]

To login to your account, visit [portal_url] at any time.

For future reference, the information you have submitted to create your account is listed below:

Name: [realtor_first] [realtor_last]
Company: [realtor_company]
Phone: [phone_number]
Email: [realtor_email]

If you have any questions about your account please feel free to reply to this email, or email [company_email] directly.

Thank you for the opportunity.

Customer Service Team
R/E Pro Photos
202-868-1113
contact@reprophotos.com
https://reprophotos.com';
    }

    private function getShootScheduledPlainText(): string
    {
        return '[greeting]!

[recipient_booking_intro]

[recipient_manage_copy_text]

Here is a summary of the shoot that was scheduled:

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographers: [assigned_photographers]
[services_provided]
Shoot total: [shoot_quote]

[shoot_notes]

[property_prep_text]

[payment_cta_text]

[cancellation_policy_text]

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
[services_provided]
Shoot total: [shoot_quote]

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
[services_provided]
Shoot total: [shoot_quote]

Updated Details:
[changes_made]

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
[services_provided]
Shoot total: [shoot_quote]

Updated Details:
[changes_made]

[shoot_notes]

Thanks for scheduling, your business is appreciated!';
    }

    private function getShootRequestDeclinedPlainText(): string
    {
        return '[greeting], [realtor_first]!

Unfortunately one of your requested shoots has been declined.

Decline Reason: [decline_reason]
Location: [shoot_location]
Requested Shoot Date: [shoot_date]
Requested Shoot Time: [shoot_time]
Photographer: [photographer_first] [photographer_last]
[services_provided]

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
[services_provided]

[shoot_notes]

' . $this->getCancellationPolicyText() . '

Thank you!';
    }

    private function getShootUpdatedPlainText(): string
    {
        return '[greeting]!

[recipient_update_intro]

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographers: [assigned_photographers]
[services_provided]

Updated Details:
[changes_made]

[shoot_notes]

[recipient_manage_copy_text]

[property_prep_text]

[cancellation_policy_text]

Thank you!';
    }

    private function getShootReadyPlainText(): string
    {
        return '[greeting], [realtor_first]!

The content for [shoot_location] is uploaded!

You can view the images by logging in to [portal_url]

Photographer: [photographer_first] [photographer_last]
[services_provided]
Shoot total: [shoot_quote]

[shoot_notes]

Payment link: [pay_link]

Thank you!';
    }

    private function getPaymentDueReminderPlainText(): string
    {
        return '[greeting]!

This is a reminder that your invoice still has an outstanding balance.

Invoice Number: [invoice_number]
Amount Due: $[amount_due]
Due Date: [due_date]

Payment link: [payment_link]

If you have already paid this invoice, please disregard this notice. If you need help, reply to this email or contact [company_email].

Thank you!';
    }

    private function getShootDeliveredPlainText(): string
    {
        return '[greeting]!

Your final media for [shoot_location] has been delivered and is ready to review.

Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Services: [services_provided]

Dashboard: [portal_url]
Small / MLS Images: [small_zip_link]
Full Resolution Images: [full_zip_link]
MLS Tour: [mls_tour_link]
Branded Tour: [branded_tour_link]

If you have any questions, please contact [company_email].

Thank you!';
    }

    private function getWeeklyInvoiceGeneratedPlainText(): string
    {
        return 'Hello {{recipient_name}},

Your weekly {{recipient_role}} invoice has been generated.

Billing Period: {{billing_period}}
Invoice Number: {{invoice_number}}
Status: {{invoice_status}}
Total: {{invoice_total}}

Included This Week:
{{invoice_items_text}}

{{invoice_next_step}}

Review your invoice here: {{dashboard_url}}

{{approval_note}}';
    }

    private function getPaymentThankYouPlainText(): string
    {
        return '[greeting], [realtor_first] [realtor_last]!

Thank you for paying for your photo shoot!

Location: [shoot_location]
Payment Date: [current_date]
Payment Amount: [payment_amount]
[services_provided]

[shoot_notes]

Once your photos are completed you will receive a Summary email.

Thank you!';
    }

    private function getShootSummaryPlainText(): string
    {
        return '[greeting], [realtor_first]!

One of your photo shoots is ready!

Location: [shoot_location]
[services_provided]

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
[services_provided]

[shoot_notes]

Thank you!';
    }

    private function getRefundSubmittedPlainText(): string
    {
        return '[greeting]!

One of your Real Estate photo shoots has been refunded.

Location: [shoot_location]
[services_provided]

[shoot_notes]

If you have any questions regarding this refund, please feel free to reply to this email, or email [company_email] directly.

Thank you!';
    }

    private function getShootOnHoldPlainText(): string
    {
        return '[greeting]!

One of your photo shoots has been placed on hold. We will be in touch to confirm next steps, and the shoot will resume once the hold is cleared.

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographers: [assigned_photographers]
Services:
[services_provided]

Notes: [shoot_notes]

You can review this shoot at any time by logging into [portal_url]

If you have any questions about this hold please reply to this email, or email [company_email] directly.

Thank you!';
    }

    private function getShootCancelledPlainText(): string
    {
        return '[greeting]!

One of your photo shoots has been cancelled.

Location: [shoot_location]
Scheduled Shoot Date: [shoot_date]
Scheduled Shoot Time: [shoot_time]
Photographers: [assigned_photographers]
Services:
[services_provided]

Notes: [shoot_notes]

If you need real estate photography services for this property in the future please reply to this email, or email [company_email] directly. You can also rebook at any time via [portal_url]

Thank you!';
    }

    private function getPaymentDuePlainText(): string
    {
        return '[greeting]!

This is a friendly reminder that a balance is due for your photo shoot at [shoot_location].

Location: [shoot_location]
Shoot total: [shoot_quote]

Pay securely here: [pay_link]

If you have already paid, please disregard this notice. If you have any questions please reply to this email, or email [company_email] directly.

Thank you!';
    }

    private function getPaymentReceiptPlainText(): string
    {
        return '[greeting]!

Thank you for your payment for your photo shoot at [shoot_location]. Here are your payment details for your records:

Location: [shoot_location]
Payment Details:
[payment_details]

You can review your shoot and payment history at any time by logging into [portal_url]

If you have any questions about this payment please reply to this email, or email [company_email] directly.

Thank you!';
    }

    private function getPropertyContactReminderTemplate(): string
    {
        return '
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
    
    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
    <p style="color: #666; font-size: 12px;">This is an automated reminder. If you have already provided this information, please disregard this message.</p>';
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

' . $this->getContactLineText() . '

' . $this->getSignOffText() . '

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
                    <span class="info-label">Services:</span><br>[services_provided_html]
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
Services:
[services_provided]

Notes: [shoot_notes]

You can view more details and manage this shoot by logging into your dashboard at [portal_url]

If you have any questions, please email [company_email]

Thank you!';
    }

    private function getPhotographerChangedTemplate(): string
    {
        $content = '
            <p>[greeting]!</p>

            <p>A photographer assignment has changed for the shoot below.</p>

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
                    <span class="info-label">Previous Photographer:</span> [previous_photographer_name]
                </div>
                <div class="info-row">
                    <span class="info-label">Current Photographer:</span> [new_photographer_name]
                </div>
                <div class="info-row">
                    <span class="info-label">Services:</span><br>[services_provided_html]
                </div>
            </div>

            <p><strong>What changed:</strong></p>
            <p>[shoot_change_summary]</p>

            <p><strong>Notes:</strong></p>
            <p>[shoot_notes]</p>

            <p>You can view the latest assignment details in your dashboard at <a href="[portal_url]">[portal_url]</a></p>

            <p>If you have any questions, please email <a href="mailto:[company_email]">[company_email]</a></p>

            <p>Thank you!</p>
        ';

        return $this->getEmailWrapper($content);
    }

    private function getPhotographerChangedPlainText(): string
    {
        return 'Hello!

A photographer assignment has changed for the shoot below.

SHOOT DETAILS:
Location: [shoot_location]
Date: [shoot_date]
Time: [shoot_time]
Previous Photographer: [previous_photographer_name]
Current Photographer: [new_photographer_name]
Services:
[services_provided]

What changed:
[shoot_change_summary]

Notes: [shoot_notes]

You can view the latest assignment details in your dashboard at [portal_url]

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






