<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\RolePermissionService;
use App\Exceptions\PublicApiResponseException;

/** Booking operations are independent of media, production and account administration. */
class ShootManagementAccess
{
    public function __construct(private readonly RolePermissionService $permissions) {}

    public function isSalesRep(?User $user): bool
    {
        return $user && $this->permissions->normalizedUserRoles($user)->contains('salesRep');
    }

    public function isRestrictedSalesRep(?User $user): bool
    {
        // A secondary rep role must not demote an existing privileged staff role.
        $primary = preg_replace('/[_\s-]/', '', strtolower((string) $user?->role));
        return $user && ! in_array($primary, ['admin', 'superadmin', 'editingmanager'], true)
            && $this->isSalesRep($user);
    }

    public function can(?User $user, string $action = 'update'): bool
    {
        return $user
            && $this->permissions->normalizedUserRoles($user)->intersect(['admin', 'superadmin', 'editing_manager', 'salesRep'])->isNotEmpty()
            && $this->permissions->userCan($user, 'shoots', $action);
    }

    public function canAdjustPricing(?User $user): bool
    {
        return $user
            && $this->permissions->normalizedUserRoles($user)->intersect(['admin', 'superadmin', 'editing_manager', 'salesRep'])->isNotEmpty()
            && $this->permissions->userCan($user, 'shoot-pricing', 'update');
    }

    public function canEdit(Shoot $shoot, ?User $user): bool
    {
        if (! $this->can($user) || $shoot->isImportDraft()) return false;
        if (! $this->isRestrictedSalesRep($user)) return true;
        $editable = ['requested', 'on_hold', 'hold_on', 'scheduled', 'booked', 'uploaded', 'completed', 'editing', 'review', 'ready'];
        return in_array(strtolower((string) $shoot->status), $editable, true)
            && in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)), $editable, true);
    }

    /** Preserve all booking fields, while refusing unrelated workflow/media writes. */
    public function normalizeSalesEdit(Shoot $shoot, User $user, array $payload): array
    {
        abort_unless($this->canEdit($shoot, $user), 403, 'This shoot is locked or you do not have permission to edit it.');
        if (! $this->isRestrictedSalesRep($user)) return $payload;
        $marketing = ['is_featured', 'featured_homepage_title', 'featured_homepage_location', 'featured_homepage_subtitle', 'featured_homepage_cta_label', 'featured_homepage_cta_href', 'featured_homepage_images', 'ghost_user_ids', 'tour_links'];
        if (array_intersect(array_keys($payload), $marketing)
            && $this->permissions->normalizedUserRoles($user)->intersect(['admin', 'superadmin', 'editing_manager'])->isEmpty()) {
            $clientRepAppearance = array_keys($payload) === ['tour_links'] && ! $shoot->rep_id
                && app(ShootMutationSupportService::class)->getClientRep((int) $shoot->client_id) === (int) $user->id;
            abort_unless((string) $shoot->rep_id === (string) $user->id || $clientRepAppearance, 403, 'Marketing access remains assignment-scoped.');
            if (isset($payload['tour_links'])) {
                abort_unless(is_array($payload['tour_links']) && ! array_diff(array_keys($payload['tour_links']), ['realtor_client_id', 'tour_style', 'tour_palette', 'header_position', 'tour_version', 'realtor_info', 'autoplay', 'show_garage']), 403, 'Tour media links are managed outside booking.');
            }
        }
        foreach (['status', 'workflow_status'] as $field) {
            if (isset($payload[$field])) abort_unless($payload[$field] === $shoot->{$field}, 403, 'Use the shoot approval, hold, resume or cancellation action to change its status.');
            unset($payload[$field]);
        }
        foreach (['editor_id', 'video_editor_id', 'payment_status', 'payment_type', 'hero_image', 'delivery_status', 'is_flagged', 'is_listing_hidden', 'complimentary_service_options'] as $field) {
            if (array_key_exists($field, $payload)) {
                $unchanged = $payload[$field] === $shoot->{$field}
                    || (is_scalar($payload[$field]) && is_scalar($shoot->{$field}) && (string) $payload[$field] === (string) $shoot->{$field});
                abort_unless($payload[$field] === null || $unchanged, 403, 'This field is managed outside booking: '.$field.'.');
                unset($payload[$field]);
            }
        }
        // Stale computed totals are echoes, never authority to change booked prices.
        foreach (['base_quote', 'total_quote', 'tax_amount', 'tax_percent', 'discount_amount'] as $field) unset($payload[$field]);
        foreach (['services', 'service_items', 'service_lines'] as $field) {
            if (! is_array($payload[$field] ?? null)) continue;
            foreach ($payload[$field] as &$line) {
                if (! is_array($line)) continue;
                foreach (['price', 'photographer_pay', 'editor_id', 'video_editor_id', 'is_deliverable', 'workflow_status', 'delivery_status', 'force_unlock_delivery', 'unlock_reason'] as $key) unset($line[$key]);
            }
            unset($line);
        }
        foreach (['admin_adjusted_total_quote', 'discount_type', 'discount_value'] as $field) {
            if (! array_key_exists($field, $payload)) continue;
            if ((string) ($payload[$field] ?? '') === (string) ($shoot->{$field} ?? '')
                || ($field === 'discount_value' && (float) ($payload[$field] ?? 0) === (float) ($shoot->{$field} ?? 0))) {
                unset($payload[$field]);
            } else {
                abort_unless($this->canAdjustPricing($user), 403, 'You do not have permission to adjust shoot pricing.');
            }
        }
        if (array_key_exists('skip_availability_check', $payload)) {
            abort_unless(! filter_var($payload['skip_availability_check'], FILTER_VALIDATE_BOOLEAN), 403, 'Scheduling conflicts must be resolved before saving.');
        }
        return $payload;
    }

    /** Content token also detects assignment changes made within the same second. */
    public function editVersion(Shoot $shoot): string
    {
        $row = $shoot->getRawOriginal();
        ksort($row);
        $lines = $shoot->serviceItems()->orderBy('id')->get()->map(fn ($line) => $line->getRawOriginal())->all();
        return hash('sha256', json_encode([$row, $lines], JSON_THROW_ON_ERROR));
    }

    public function assertVersion(Shoot $shoot, ?string $expected): void
    {
        if ($expected && ! hash_equals($expected, $this->editVersion($shoot->fresh()))) {
            throw new PublicApiResponseException(response()->json(['message' => 'This shoot changed while you were editing. Reload the latest shoot and review your draft before saving.', 'code' => 'shoot_edit_conflict'], 409));
        }
    }
}
