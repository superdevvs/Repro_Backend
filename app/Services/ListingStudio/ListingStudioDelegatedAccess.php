<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioUpload;
use App\Models\User;

final class ListingStudioDelegatedAccess
{
    public static function assertActor(User $actor): void
    {
        // Financial delegation follows the primary role, never a secondary role or impersonation.
        if (! in_array(preg_replace('/[\s_-]/', '', strtolower(trim((string) $actor->role))), ['salesrep', 'rep', 'representative', 'superadmin'], true) || ! $actor->isAccountEligibleForAuthentication()) {
            throw new PublicApiException('Your account cannot purchase for clients.', 'delegated_billing_forbidden', 403);
        }
    }

    public static function assert(User $actor, User $client, bool $write = false): void
    {
        self::assertActor($actor);
        ListingStudioClientAccess::assert($client);
        if ($write && ! config('listing-studio.delegated_enabled', false)) {
            throw new PublicApiException('Purchasing for clients is temporarily unavailable.', 'delegated_billing_unavailable', 503);
        }
    }

    public static function assertSource(User $actor, User $client, string $kind, string $id): void
    {
        self::assert($actor, $client);
        $allowed = match ($kind) {
            'upload' => ListingStudioUpload::whereKey($id)->where('client_id', $client->id)->where('actor_id', $actor->id)->exists(),
            'paid_item' => ListingStudioOrderItem::whereKey($id)->where('client_id', $client->id)
                ->whereHas('order', fn ($orders) => $orders->where('actor_id', $actor->id))->exists(),
            default => false,
        };
        abort_unless($allowed, 404);
    }

    public static function order(User $actor, User $client, string $id): ListingStudioOrder
    {
        self::assert($actor, $client);

        return ListingStudioOrder::where('client_id', $client->id)->where('actor_id', $actor->id)->findOrFail($id);
    }
}
