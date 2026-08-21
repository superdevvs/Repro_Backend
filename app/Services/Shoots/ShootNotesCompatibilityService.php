<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootNote;
use App\Models\User;

class ShootNotesCompatibilityService
{
    private const FIELD_MAP = [
        'shoot_notes' => [ShootNote::TYPE_SHOOT, ShootNote::VISIBILITY_CLIENT_VISIBLE],
        'company_notes' => [ShootNote::TYPE_COMPANY, ShootNote::VISIBILITY_INTERNAL],
        'photographer_notes' => [ShootNote::TYPE_PHOTOGRAPHER, ShootNote::VISIBILITY_PHOTOGRAPHER_ONLY],
        'editor_notes' => [ShootNote::TYPE_EDITING, ShootNote::VISIBILITY_INTERNAL],
    ];

    public function syncScalarField(
        Shoot $shoot,
        string $field,
        mixed $content,
        ?User $author,
        mixed $previousContent = null
    ): void {
        if (! isset(self::FIELD_MAP[$field])) {
            return;
        }

        [$type, $visibility] = self::FIELD_MAP[$field];
        $value = trim((string) ($content ?? ''));
        $previous = trim((string) ($previousContent ?? ''));
        $managedSources = ['legacy_scalar:'.$field, 'scalar_compat:'.$field];

        $managed = $shoot->notes()
            ->whereIn('source', $managedSources)
            ->latest('id')
            ->first();

        if (! $managed && $previous !== '') {
            $managed = $shoot->notes()
                ->where('type', $type)
                ->where('visibility', $visibility)
                ->where('content', $previous)
                ->latest('id')
                ->first();
        }

        if ($value === '') {
            $managed?->delete();
            return;
        }

        $normalized = preg_replace('/\s+/u', ' ', $value) ?: $value;
        $sourceHash = hash('sha256', $shoot->id.'|'.$field.'|'.$normalized);
        $equivalent = $shoot->notes()
            ->where('type', $type)
            ->where('visibility', $visibility)
            ->where('content', $value)
            ->first();

        if ($equivalent && (! $managed || $equivalent->isNot($managed))) {
            $managed?->delete();
            return;
        }

        $note = $managed ?? new ShootNote(['shoot_id' => $shoot->id]);
        $note->fill([
            'author_id' => $author?->id,
            'type' => $type,
            'visibility' => $visibility,
            'content' => $value,
            'source' => 'scalar_compat:'.$field,
            'source_hash' => $sourceHash,
        ]);
        $note->shoot_id = $shoot->id;
        $note->save();
    }

    public function scalarFieldFor(string $type, string $visibility): ?string
    {
        foreach (self::FIELD_MAP as $field => [$mappedType, $mappedVisibility]) {
            if ($type === $mappedType && $visibility === $mappedVisibility) {
                return $field;
            }
        }

        return null;
    }
}
