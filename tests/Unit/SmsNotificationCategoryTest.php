<?php

namespace Tests\Unit;

use App\Services\Messaging\SmsNotificationPreferences;
use App\Services\Messaging\SmsTemplateContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsNotificationCategoryTest extends TestCase
{
    public static function types(): array
    {
        $groups = [
            'bookingUpdates' => ['SHOOT_BOOKED', 'SHOOT_SCHEDULED', 'SHOOT_REQUESTED', 'SHOOT_REQUEST_APPROVED',
                'SHOOT_REQUEST_MODIFIED', 'SHOOT_REQUEST_DECLINED', 'SHOOT_UPDATED', 'SHOOT_ON_HOLD',
                'SHOOT_CANCELED', 'SHOOT_CANCELLED', 'SHOOT_REMOVED', 'SHOOT_DELETED', 'PHOTOGRAPHER_ASSIGNED', 'PHOTOGRAPHER_CHANGED',
                'SHOOT_CANCELLATION_REQUESTED', 'SHOOT_CANCELLATION_APPROVED', 'SHOOT_CANCELLATION_REJECTED'],
            'shootReminders' => ['SHOOT_REMINDER', 'PHOTOGRAPHER_SHOOT_REMINDER', 'PROPERTY_CONTACT_REMINDER'],
            'deliveryUpdates' => ['SHOOT_COMPLETED', 'SHOOT_READY', 'MEDIA_UPLOAD_COMPLETE', 'EDITING_COMPLETE', 'PHOTO_UPLOADED'],
            'payments' => ['SHOOT_PAYMENT_REMINDER', 'SHOOT_PAID', 'PAYMENT_DUE', 'PAYMENT_RECEIPT', 'PAYMENT_COMPLETED',
                'PAYMENT_REFUNDED', 'INVOICE_DUE', 'INVOICE_OVERDUE', 'INVOICE_SUMMARY', 'WEEKLY_AUTOMATED_INVOICING',
                'WEEKLY_REP_INVOICE', 'WEEKLY_PAYOUT_REPORT', 'WEEKLY_PAYOUT_DIGEST', 'INVOICE_PAID', 'PAYMENT_FAILED', 'WEEKLY_PHOTOGRAPHER_INVOICE'],
            'accountUpdates' => ['ACCOUNT_CREATED', 'ACCOUNT_VERIFIED', 'PHONE_NUMBER_CHANGED_PREVIOUS', 'PHONE_NUMBER_CHANGED_NEW',
                'VOICE_CALLER_VERIFICATION', 'VOICE_PHONE_VERIFICATION'],
            'other' => ['MANUAL', 'AI_SMS_AGENT', 'CUSTOM_EVENT', 'WEEKLY_SALES_REPORT'],
        ];
        $rows = [];
        foreach ($groups as $category => $types) {
            foreach ($types as $type) {
                $rows[$type] = [$type, $category];
            }
        }

        return $rows;
    }

    #[DataProvider('types')]
    public function test_each_actual_trigger_maps_to_its_setting(string $type, string $category): void
    {
        $preferences = new SmsNotificationPreferences;
        $this->assertSame($category, $preferences->category(['automation_trigger' => $type]));
    }

    public function test_all_shipped_sms_templates_have_a_specific_category(): void
    {
        $preferences = new SmsNotificationPreferences;
        foreach (array_keys(SmsTemplateContent::all()) as $slug) {
            $this->assertNotSame('other', $preferences->category(['notification_type' => $slug]), $slug);
        }
    }
}
