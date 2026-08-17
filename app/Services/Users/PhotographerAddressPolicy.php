<?php

namespace App\Services\Users;

use App\Models\PhotographerAddressChangeRequest;
use App\Models\User;

class PhotographerAddressPolicy
{
    public function isPhotographer(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = $this->normalizeRole($user->role);
        $secondary = collect(is_array($user->secondary_roles) ? $user->secondary_roles : [])
            ->map(fn ($value) => $this->normalizeRole(is_string($value) ? $value : null));

        return $role === 'photographer' || $secondary->contains('photographer');
    }

    public function canViewFullAddress(?User $viewer, User $subject): bool
    {
        if (!$this->isPhotographer($subject)) {
            return true;
        }

        if (!$viewer) {
            return false;
        }

        if ((int) $viewer->id === (int) $subject->id) {
            return true;
        }

        return in_array($this->normalizeRole($viewer->role), ['admin', 'superadmin'], true);
    }

    public function canApproveAddressChanges(?User $viewer): bool
    {
        if (!$viewer) {
            return false;
        }

        return in_array($this->normalizeRole($viewer->role), ['admin', 'superadmin'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function presentSubjectForViewer(array $payload, ?User $viewer, User $subject): array
    {
        $pending = $this->pendingChangeFor($subject);

        if ($this->canViewFullAddress($viewer, $subject)) {
            if ($pending) {
                $payload['pending_address_change'] = $pending->toPresentation();
            }

            return $payload;
        }

        unset($payload['address']);
        $payload['address'] = null;

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            unset($payload['metadata']['address'], $payload['metadata']['homeAddress']);
        }

        if ($pending && $this->canApproveAddressChanges($viewer)) {
            $payload['pending_address_change'] = $pending->toPresentation();
        } else {
            $payload['pending_address_change'] = $pending ? [
                'id' => $pending->id,
                'status' => $pending->status,
                'city' => $pending->city,
                'state' => $pending->state,
                'zip' => $pending->zip,
                'submitted_at' => $pending->submitted_at?->toIso8601String(),
            ] : null;
            if (isset($payload['pending_address_change']['street_address'])) {
                unset($payload['pending_address_change']['street_address']);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array{changed: bool, request: ?PhotographerAddressChangeRequest}
     */
    public function queueSelfServiceChange(User $photographer, array $incoming): array
    {
        $next = [
            'street_address' => $this->normalize($incoming['address'] ?? $incoming['street_address'] ?? $photographer->address),
            'city' => $this->normalize($incoming['city'] ?? $photographer->city),
            'state' => $this->normalize($incoming['state'] ?? $photographer->state),
            'zip' => $this->normalize($incoming['zip'] ?? $incoming['zipcode'] ?? $photographer->zip),
        ];

        $current = [
            'street_address' => $this->normalize($photographer->address),
            'city' => $this->normalize($photographer->city),
            'state' => $this->normalize($photographer->state),
            'zip' => $this->normalize($photographer->zip),
        ];

        if ($next === $current) {
            return ['changed' => false, 'request' => $this->pendingChangeFor($photographer)];
        }

        PhotographerAddressChangeRequest::query()
            ->where('user_id', $photographer->id)
            ->where('status', PhotographerAddressChangeRequest::STATUS_PENDING)
            ->update([
                'status' => PhotographerAddressChangeRequest::STATUS_REJECTED,
                'review_note' => 'Superseded by a newer address change request.',
                'reviewed_at' => now(),
            ]);

        $request = PhotographerAddressChangeRequest::query()->create([
            'user_id' => $photographer->id,
            'street_address' => $next['street_address'],
            'city' => $next['city'],
            'state' => $next['state'],
            'zip' => $next['zip'],
            'status' => PhotographerAddressChangeRequest::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        return ['changed' => true, 'request' => $request];
    }

    public function approve(PhotographerAddressChangeRequest $request, User $reviewer): PhotographerAddressChangeRequest
    {
        $photographer = $request->photographer;
        $photographer->forceFill([
            'address' => $request->street_address,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
        ])->save();

        $request->forceFill([
            'status' => PhotographerAddressChangeRequest::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        return $request->fresh();
    }

    public function reject(PhotographerAddressChangeRequest $request, User $reviewer, ?string $note = null): PhotographerAddressChangeRequest
    {
        $request->forceFill([
            'status' => PhotographerAddressChangeRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        return $request->fresh();
    }

    public function pendingChangeFor(User $photographer): ?PhotographerAddressChangeRequest
    {
        return PhotographerAddressChangeRequest::query()
            ->where('user_id', $photographer->id)
            ->where('status', PhotographerAddressChangeRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', (string) $role));
    }
}
