<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\StandingEditorNote;
use App\Models\User;
use App\Support\DatabaseSql;
use Illuminate\Support\Collection;

/**
 * Resolves the standing editor notes that apply to a client or shoot.
 *
 * Open shoots read the live notes, so a note added or edited today reaches every
 * shoot that has not been delivered yet. A delivered shoot shows the snapshot
 * taken at delivery (or nothing, for shoots delivered before notes existed).
 *
 * One instance caches the active notes, so resolve it once per request when
 * presenting a list of shoots.
 */
class StandingEditorNoteService
{
    private const FROZEN_STATUSES = [Shoot::STATUS_DELIVERED, Shoot::STATUS_ACTIVE];

    /** @var Collection<int, StandingEditorNote>|null */
    private ?Collection $activeNotes = null;

    public function canManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($this->role($user), ['admin', 'superadmin'], true)
            || app(ShootManagementAccess::class)->isSalesRep($user);
    }

    public function canRead(?User $user): bool
    {
        return $this->canManage($user) || ($user && $this->role($user) === 'editing_manager');
    }

    /** Roles that see standing notes on a shoot they can already open. */
    public function canSeeOnShoot(?User $user): bool
    {
        return $this->canRead($user) || ($user && $this->role($user) === 'editor');
    }

    /** @return Collection<int, StandingEditorNote> brokerage notes first, then the client's own note */
    public function notesForClient(?User $client): Collection
    {
        if (! $client) {
            return collect();
        }

        $notes = $this->activeNotes();
        $brokerage = $notes->filter(fn (StandingEditorNote $note) => $note->isBrokerage()
            && $note->matchesCompany($client->company_name));
        $own = $notes->filter(fn (StandingEditorNote $note) => ! $note->isBrokerage()
            && (int) $note->client_id === (int) $client->id);

        return $brokerage->sortBy('id')->concat($own)->values();
    }

    /**
     * @return array{hidden: bool, frozen: bool, notes: list<array<string, mixed>>}|null
     *         null when the viewer must not see standing notes at all.
     */
    public function presentForShoot(Shoot $shoot, ?User $viewer): ?array
    {
        if (! $this->canSeeOnShoot($viewer)) {
            return null;
        }

        $hidden = (bool) $shoot->standing_editor_notes_hidden;
        $frozen = $this->isFrozen($shoot);
        $notes = $frozen
            ? $this->snapshotNotes($shoot)
            : $this->notesForClient($this->clientFor($shoot))->map(fn ($note) => $this->present($note))->all();

        if ($hidden && ! $this->canRead($viewer)) {
            // Editors never see a note an admin switched off for this shoot.
            $notes = [];
        }

        return ['hidden' => $hidden, 'frozen' => $frozen, 'notes' => array_values($notes)];
    }

    /** Store the notes that applied at delivery so later edits do not rewrite history. */
    public function freeze(Shoot $shoot): void
    {
        $notes = $this->notesForClient($this->clientFor($shoot))
            ->map(fn ($note) => $this->present($note))->values()->all();
        if ($notes === [] && $shoot->standing_editor_notes_snapshot === null) {
            return;
        }

        $shoot->forceFill(['standing_editor_notes_snapshot' => $notes])->saveQuietly();
    }

    /** @return array{count: int, clients: list<array{id: int, name: string, company_name: ?string}>} */
    public function matchingClients(string $companyMatch, int $limit = 25): array
    {
        $needle = mb_strtolower(trim($companyMatch));
        if ($needle === '') {
            return ['count' => 0, 'clients' => []];
        }

        $query = User::query()
            ->where('role', 'client')
            ->whereNotNull('company_name')
            ->whereRaw("LOWER(company_name) LIKE ? ESCAPE '!'", ['%'.DatabaseSql::escapeLike($needle).'%']);

        return [
            'count' => (clone $query)->count(),
            'clients' => $limit <= 0 ? [] : $query->orderBy('name')->limit($limit)->get(['id', 'name', 'company_name'])
                ->map(fn (User $client) => [
                    'id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'company_name' => $client->company_name,
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function present(StandingEditorNote $note): array
    {
        return [
            'id' => (int) $note->id,
            'scope' => $note->scope,
            'client_id' => $note->client_id,
            'company_match' => $note->company_match,
            'label' => $note->isBrokerage() ? trim((string) $note->company_match).' brokerage' : 'This client',
            'content' => (string) $note->content,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    public function isFrozen(Shoot $shoot): bool
    {
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));

        return in_array($status, self::FROZEN_STATUSES, true);
    }

    /** @return list<array<string, mixed>> */
    private function snapshotNotes(Shoot $shoot): array
    {
        $snapshot = $shoot->standing_editor_notes_snapshot;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) ? array_values(array_filter($snapshot, 'is_array')) : [];
    }

    private function clientFor(Shoot $shoot): ?User
    {
        if (! $shoot->client_id) {
            return null;
        }
        $client = $shoot->relationLoaded('client') ? $shoot->client : null;
        if ($client && array_key_exists('company_name', $client->getAttributes())) {
            return $client;
        }

        return User::query()->find($shoot->client_id, ['id', 'company_name']);
    }

    /** @return Collection<int, StandingEditorNote> */
    private function activeNotes(): Collection
    {
        return $this->activeNotes ??= StandingEditorNote::query()->active()->orderBy('id')->get();
    }

    private function role(User $user): string
    {
        return strtolower(str_replace(['-', ' '], '_', (string) $user->role));
    }
}
