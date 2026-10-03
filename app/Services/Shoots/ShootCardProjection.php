<?php
namespace App\Services\Shoots;

use Illuminate\Support\Arr;

final class ShootCardProjection
{
    public static function from(array $shoot): array
    {
        $card = Arr::only($shoot, [
            'id', 'client_id', 'photographer_id', 'editor_id', 'rep_id', 'status', 'workflow_status',
            'address', 'city', 'state', 'zip', 'latitude', 'longitude', 'location',
            'scheduled_date', 'scheduled_at', 'time', 'timezone', 'scheduled_timezone', 'completed_at', 'completed_date',
            'client', 'photographer', 'editor', 'rep', 'services', 'service_items', 'editor_assignments',
            'base_quote', 'tax_amount', 'total_quote', 'payment_status', 'payment_type', 'payment_summary', 'financials', 'payment',
            'bypass_paywall', 'client_release_access', 'can_download', 'can_view_originals', 'permissions',
            'hero_image', 'preview_images', 'media_summary', 'package_name', 'package_details',
            'expected_final_count', 'bracket_mode', 'expected_raw_count', 'raw_photo_count', 'edited_photo_count',
            'raw_missing_count', 'edited_missing_count', 'missing_raw', 'missing_final',
            'notes', 'shoot_notes', 'editor_notes', 'photographer_notes', 'admin_issue_notes',
            'is_flagged', 'issues_resolved_at', 'hold_reason', 'hold_requested_at', 'hold_status',
            'cancellation_requested_at', 'cancellation_reason', 'action_requests', 'actions',
            'created_by', 'created_at', 'updated_at', 'weather', 'property_slug', 'tour_purchased',
            'is_featured', 'featured_pending', 'featured_status', 'featured_requested_at',
            'ghost_user_ids', 'is_ghost_visible_for_user', 'approved_at', 'declined_at', 'declined_reason',
            'primary_action', 'delivery_status', 'total_paid', 'tax_rate', 'tax_percent', 'invoice_adjustments_total', 'order_total',
            'is_private_listing', 'ghost_users', 'company_notes', 'issues_resolved_by',
            'status_at_hold', 'status_before_hold', 'previous_status', 'previous_workflow_status', 'workflow_status_at_hold',
            'photos_uploaded_at', 'editing_completed_at', 'admin_verified_at', 'submitted_for_review_at',
        ]);
        foreach (['client', 'photographer', 'editor', 'rep'] as $person) {
            if (is_array($card[$person] ?? null)) $card[$person] = Arr::only($card[$person], ['id', 'name', 'email', 'phone', 'phonenumber', 'avatar', 'company_name', 'timezone']);
        }
        if (is_array($card['services'] ?? null)) {
            $card['services'] = array_map(fn ($service) => is_array($service)
                ? Arr::only($service, ['id', 'name', 'category', 'category_name', 'price', 'quantity', 'photo_count', 'photographer_id', 'resolved_photographer_id', 'editor_id', 'video_editor_id']) : $service, $card['services']);
        }
        if (is_array($card['service_items'] ?? null)) {
            $card['service_items'] = array_map(fn ($item) => Arr::only($item, ['id', 'shoot_service_id', 'service_id', 'name', 'service_name', 'photographer_id', 'resolved_photographer_id', 'photographer', 'editor_id', 'video_editor_id', 'editor', 'video_editor', 'price', 'quantity', 'is_invoice_adjustment', 'amount', 'status', 'editing_completed_at', 'video_editing_completed_at', 'client_release_access', 'category', 'category_name', 'workflow_status', 'delivery_status', 'scheduled_at', 'is_deliverable', 'ready_at', 'delivered_at', 'supports_photo_intake', 'supports_video_intake', 'upload_intake_type', 'uses_hdr_brackets', 'bracket_mode', 'effective_bracket_mode', 'expected_raw_count', 'photo_count', 'paid_amount', 'balance_due', 'payment_status', 'unit_label', 'unit_kind']), $card['service_items']);
        }
        return $card;
    }
}
