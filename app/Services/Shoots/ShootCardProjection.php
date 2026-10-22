<?php

namespace App\Services\Shoots;

use Illuminate\Support\Arr;

final class ShootCardProjection
{
    public static function from(array $shoot): array
    {
        // Cards populate the shared shoot context, which survives navigation to
        // Accounts and Calendar. Keep operational facts and server capabilities;
        // only detail-only provider payloads and duplicate collections are omitted.
        $card = Arr::only($shoot, [
            'id', 'client_id', 'photographer_id', 'editor_id', 'rep_id', 'status', 'workflow_status',
            'address', 'address2', 'city', 'state', 'zip', 'latitude', 'longitude', 'location', 'property_details',
            'scheduled_date', 'scheduled_at', 'time', 'timezone', 'scheduled_timezone', 'completed_at', 'completed_date',
            'scheduled_instant', 'schedule_timezone', 'cancellation_fee_window', 'duration_minutes',
            'alternate_scheduled_date', 'alternate_time', 'alternate_scheduled_at', 'requested_photographers',
            'external_booking_warnings', 'external_booking_mapping_status',
            'client', 'photographer', 'editor', 'rep', 'services', 'services_list', 'service_items', 'editor_assignments',
            'service_presentation', 'units', 'units_revision', 'client_shoots_count',
            'can_view_video_editor_context', 'canViewVideoEditorContext',
            'can_submit_raw', 'canSubmitRaw', 'can_submit_edits', 'canSubmitEdits',
            'can_view_invoice', 'canViewInvoice', 'can_finalize_no_media', 'canFinalizeNoMedia',
            'can_remove_all_services', 'canRemoveAllServices',
            'canManageBooking', 'canManageShootActions', 'canAdjustShootPricing', 'editVersion',
            'base_quote', 'tax_amount', 'total_quote', 'payment_status', 'payment_type', 'payment_summary', 'financials', 'payment',
            'service_subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discounted_subtotal',
            'overpayment_amount', 'last_payment_date', 'last_payment_type',
            'total_photographer_pay', 'photographer_pay', 'photographer_paid_at', 'paid_at_photographer',
            'bypass_paywall', 'client_release_access', 'can_download', 'can_view_originals', 'permissions',
            'hero_image', 'preview_images', 'media_summary', 'package_name', 'package_details',
            'expected_final_count', 'bracket_mode', 'expected_raw_count', 'raw_photo_count', 'edited_photo_count', 'extra_photo_count',
            'raw_missing_count', 'edited_missing_count', 'missing_raw', 'missing_final',
            'notes', 'shoot_notes', 'editor_notes', 'photographer_notes', 'admin_issue_notes',
            'is_flagged', 'issues_resolved_at', 'hold_reason', 'hold_requested_at', 'hold_requested_by', 'hold_status',
            'cancellation_requested_at', 'cancellation_reason', 'action_requests', 'actions',
            'created_by', 'created_at', 'updated_at', 'weather', 'property_slug', 'tour_purchased',
            'is_featured', 'featured_pending', 'featured_status', 'featured_requested_at',
            'featured_requested_by', 'featured_approved_at', 'featured_approved_by', 'is_listing_hidden',
            'featured_homepage_title', 'featured_homepage_location', 'featured_homepage_subtitle',
            'featured_homepage_cta_label', 'featured_homepage_cta_href', 'featured_homepage_images',
            'ghost_user_ids', 'is_ghost_visible_for_user', 'approved_at', 'declined_at', 'declined_reason',
            'primary_action', 'delivery_status', 'total_paid', 'tax_rate', 'tax_percent', 'invoice_adjustments_total', 'order_total',
            'is_private_listing', 'ghost_users', 'company_notes', 'issues_resolved_by',
            'status_at_hold', 'status_before_hold', 'previous_status', 'previous_workflow_status', 'workflow_status_at_hold',
            'photos_uploaded_at', 'editing_completed_at', 'admin_verified_at', 'submitted_for_review_at',
            'shoot_type', 'reshoot_of_shoot_id', 'root_shoot_id', 'reshoot_classification',
            'reshoot_parent', 'reshoot_root', 'reshoot_children', 'reshoot_summary', 'complimentary_reshoots',
            'reshoot_reason_code', 'reshoot_reason_note', 'reshoot_service_links',
            'compensation_summary', 'complimentary_reshoot_overview',
            'tour_links', 'iguide_tour_url', 'iguide_floorplans', 'iguide_property_id', 'iguide_work_order_id', 'iguide_last_synced_at',
            'cubicasa_tour_url', 'cubicasa_floorplans', 'cubicasa_status', 'cubicasa_product_type',
            'cubicasa_order_id', 'cubicasa_external_id', 'cubicasa_last_synced_at',
            'mmm_status', 'mmm_order_number', 'mmm_buyer_cookie', 'mmm_redirect_url',
            'mmm_last_punchout_at', 'mmm_last_order_at', 'mmm_last_error',
        ]);
        foreach (['client', 'photographer', 'editor', 'rep'] as $person) {
            if (is_array($card[$person] ?? null)) {
                $card[$person] = self::person($card[$person]);
            }
        }

        // These service records have already been scoped and presented for the
        // viewer. Further pruning loses unit scheduling, assignment, progress,
        // intake and payment facts consumed outside the dashboard.
        if (is_array($card['units'] ?? null)) {
            $card['units'] = array_map(function (array $unit): array {
                if (is_array($unit['provider_data'] ?? null)) {
                    $unit['provider_data'] = self::providerSummary($unit['provider_data']);
                }

                return $unit;
            }, $card['units']);
        }

        return $card;
    }

    private static function person(array $person): array
    {
        $result = Arr::only($person, ['id', 'name', 'email', 'phone', 'phonenumber', 'avatar', 'company_name', 'timezone', 'email_verified', 'emailVerified', 'total_shoots']);
        if (is_array($person['rep'] ?? null)) {
            $result['rep'] = self::person($person['rep']);
        }

        return $result;
    }

    private static function providerSummary(array $data): array
    {
        $summary = Arr::only($data, [
            'iguide_status', 'iguide_tour_url', 'iguide_last_synced_at',
            'cubicasa_status', 'cubicasa_tour_url', 'cubicasa_product_type', 'cubicasa_last_synced_at',
        ]);
        if (is_array($data['lines'] ?? null)) {
            $summary['lines'] = array_map(fn ($line): array => is_array($line) ? self::providerSummary($line) : [], $data['lines']);
        }

        return $summary;
    }
}
