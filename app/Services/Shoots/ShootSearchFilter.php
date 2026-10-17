<?php

namespace App\Services\Shoots;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared shoot text search used by Shoot History, the operational listing, and
 * GlobalCommandBar (via GET /api/shoots?tab=all&search= / omitted-tab search).
 * Keep callers on this one helper so matching cannot drift apart.
 */
class ShootSearchFilter
{
    public function apply(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $idCast = $query->getConnection()->getDriverName() === 'sqlite'
            ? 'CAST(shoots.id AS TEXT)'
            : 'CAST(shoots.id AS CHAR)';

        $viewer = auth()->user();
        $isEditor = $viewer && app(ShootAuthorizationSupport::class)->hasRole($viewer, ['editor']);
        $namedVideoShoots = [];
        if ($isEditor && $viewer->canEditLane('video')) {
            // Reuse the same per-shoot policy as the presenter. A hidden client
            // email/company must not become a search-result or count oracle.
            $namedVideoShoots = (clone $query)->whereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$term}%"))
                ->with('serviceItems.service')->get()
                ->filter(fn ($shoot) => app(ShootAuthorizationSupport::class)->canVideoEditorReadClientContext($shoot, $viewer))
                ->modelKeys();
        }

        return $query->where(function (Builder $scope) use ($term, $idCast, $isEditor, $namedVideoShoots) {
            $scope->where('address', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhere('state', 'like', "%{$term}%")
                ->orWhere('zip', 'like', "%{$term}%")
                ->orWhereHas('photographer', function (Builder $photographerQuery) use ($term) {
                    $photographerQuery->where('name', 'like', "%{$term}%");
                });

            if (!$isEditor) {
                $scope->orWhereHas('client', function (Builder $clientQuery) use ($term) {
                    // Soft-deleted clients are excluded by User SoftDeletes.
                    $clientQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phonenumber', 'like', "%{$term}%")
                        ->orWhere('company_name', 'like', "%{$term}%");
                });
            } elseif ($namedVideoShoots !== []) {
                $scope->orWhereIn('shoots.id', $namedVideoShoots);
            }

            // Command bar historically matched shoot ids client-side. Keep id in
            // the shared BE filter. Pure digits: exact int match (zero-padded
            // inputs work via (int) cast). Substring LIKE on CAST(id) only when
            // the term is long enough to avoid matching every zip that contains
            // a single digit.
            if (ctype_digit($term)) {
                $scope->orWhere('shoots.id', (int) $term);
                $scope->orWhereRaw($idCast.' = ?', [$term]);
                if (strlen($term) >= 3) {
                    $scope->orWhereRaw($idCast.' LIKE ?', ["%{$term}%"]);
                }
            }
        });
    }
}
