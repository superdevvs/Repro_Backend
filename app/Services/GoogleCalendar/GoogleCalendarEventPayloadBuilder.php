<?php

namespace App\Services\GoogleCalendar;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use RuntimeException;

class GoogleCalendarEventPayloadBuilder
{
    public function __construct(
        protected ShootMutationSupportService $support
    ) {
    }

    public function build(Shoot $shoot, ?User $user = null): array
    {
        $shoot->loadMissing('services');

        if (!$shoot->scheduled_at) {
            throw new RuntimeException('Scheduled shoots are required for Google Calendar sync.');
        }

        $timezone = $user?->timezone ?: config('app.timezone', 'UTC');
        $start = $shoot->scheduled_at->copy()->timezone($timezone);
        $end = $start->copy()->addMinutes($this->support->calculateShootDurationFromShoot($shoot));

        return array_filter([
            'summary' => $this->buildTitle($shoot),
            'location' => $this->support->formatFullAddress($shoot),
            'description' => $this->buildDescription($shoot),
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'extendedProperties' => [
                'private' => array_filter([
                    'repro_shoot_id' => (string) $shoot->id,
                    'repro_photographer_id' => $user?->id ? (string) $user->id : null,
                ]),
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    protected function buildTitle(Shoot $shoot): string
    {
        $serviceNames = $shoot->services
            ->pluck('name')
            ->map(fn ($name) => $this->formatServiceLabel((string) $name))
            ->filter()
            ->values();

        return $serviceNames->isNotEmpty()
            ? $serviceNames->implode(' + ')
            : 'Shoot';
    }

    protected function buildDescription(Shoot $shoot): ?string
    {
        $customerFacingNotes = trim((string) ($shoot->shoot_notes ?: $shoot->notes ?: ''));
        $photographerNotes = trim((string) ($shoot->photographer_notes ?: ''));
        $services = $shoot->services
            ->pluck('name')
            ->map(fn ($name) => $this->formatServiceLabel((string) $name))
            ->filter()
            ->values();

        $sections = [];

        if ($services->isNotEmpty()) {
            $sections[] = "Services\n" . $services->implode(' + ');
        }

        if ($customerFacingNotes !== '') {
            $sections[] = "Shoot Notes / Access Information\n" . $this->formatBodyText($customerFacingNotes);
        }

        if ($photographerNotes !== '') {
            $sections[] = "Photographer Notes\n" . $this->formatBodyText($photographerNotes);
        }

        if ($sections === []) {
            return null;
        }

        return implode("\n\n", $sections);
    }

    protected function formatServiceLabel(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function formatBodyText(string $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $normalized = collect($lines)
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values();

        return $normalized->implode("\n");
    }
}
