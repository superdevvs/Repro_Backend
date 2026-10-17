<?php

namespace App\Services\Search;

use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Database\Eloquent\Builder;

class AccountSearchQuery
{
    public function forViewer(User $viewer, string $term, array $filters = []): Builder
    {
        abort_unless(app(RolePermissionService::class)->userCan($viewer, 'accounts', 'view'), 403);
        $roles = array_map(fn ($role) => strtolower(str_replace(['_', '-', ' '], '', $role)), array_merge([$viewer->role], $viewer->secondary_roles ?? []));
        $staff = count(array_intersect($roles, ['admin', 'superadmin', 'editingmanager'])) > 0;
        $rep = count(array_intersect($roles, ['salesrep', 'rep', 'representative'])) > 0;
        abort_unless($staff || $rep, 403);
        $query = User::query();
        if (!$staff && $rep) {
            // Matches UserController::getSalesRepVisibleAccounts: all clients and
            // primary/secondary photographers, never staff directory records.
            $query->where(fn (Builder $q) => $q->whereIn('role', ['client', 'photographer'])->orWhereJsonContains('secondary_roles', 'photographer'));
        }
        if (($filters['role'] ?? 'all') !== 'all') $query->where('role', $filters['role']);
        if (($filters['status'] ?? '') !== '') $query->where('account_status', $filters['status']);
        if (($filters['rep'] ?? 'all') !== 'all') {
            $rep = $filters['rep'];
            // Match the directory's legacy rep-name filter, including creator
            // fallback and ID-only assignments. Never infer a superadmin as rep.
            $explicitId = "COALESCE(NULLIF(json_extract(users.metadata, '$.accountRepId'), ''), NULLIF(json_extract(users.metadata, '$.account_rep_id'), ''), NULLIF(json_extract(users.metadata, '$.repId'), ''), NULLIF(json_extract(users.metadata, '$.rep_id'), ''))";
            $rawId = "COALESCE($explicitId, users.created_by_id)";
            $metadataName = "COALESCE(NULLIF(json_extract(users.metadata, '$.accountRep'), ''), NULLIF(json_extract(users.metadata, '$.account_rep'), ''), NULLIF(json_extract(users.metadata, '$.rep'), ''))";
            $recordName = "(SELECT account_rep.name FROM users AS account_rep WHERE account_rep.id = $rawId AND account_rep.deleted_at IS NULL AND (account_rep.role <> 'superadmin' OR $explicitId IS NOT NULL) LIMIT 1)";
            $creatorIsSuperadmin = "EXISTS (SELECT 1 FROM users AS creator WHERE creator.id = users.created_by_id AND creator.role = 'superadmin' AND creator.deleted_at IS NULL)";
            $legacyName = "CASE WHEN $creatorIsSuperadmin THEN NULL ELSE NULLIF(users.created_by_name, '') END";
            $name = "COALESCE($metadataName, $recordName, $legacyName)";
            if ($rep === 'unassigned') $query->whereRaw("$rawId IS NULL AND $name IS NULL");
            else $query->whereRaw("LOWER(TRIM($name)) = ?", [mb_strtolower(trim($rep))]);
        }
        $term = trim($term);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $digits = preg_match('/^[+\d\s().\/-]+$/', $term) ? preg_replace('/\D/', '', $term) : '';
            $query->where(function (Builder $q) use ($like, $term, $digits) {
                foreach (['name', 'email', 'company_name'] as $column) $q->orWhereRaw('LOWER('.$column.") LIKE LOWER(?) ESCAPE '\\'", [$like]);
                if (ctype_digit($term)) $q->orWhere('users.id', (int) $term);
                if ($digits !== '') {
                    foreach (['phone', 'phonenumber', 'office_phone'] as $column) {
                        $normalized = $column;
                        foreach ([' ', '+', '-', '(', ')', '.', '/'] as $separator) $normalized = "REPLACE($normalized, '$separator', '')";
                        $q->orWhereRaw($normalized.' LIKE ?', ['%'.$digits.'%']);
                    }
                }
            });
        }
        return $query;
    }
}
