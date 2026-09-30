<?php

namespace App\Services\GoogleCalendar;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use RuntimeException;

class GoogleCalendarEventPayloadBuilder
{
    /**
     * Map of lowercased shoot status to Google Calendar event colorId. Every value is within
     * Google's supported event color range ("1"-"11"). Unknown statuses fall back to the
     * default colorId ("9", Blueberry) via resolveColorId(). See design "Color mapping".
     */
    protected const STATUS_COLOR_MAP = [
        'scheduled' => '9',  // Blueberry (blue)
        'requested' => '5',  // Banana (yellow)
        'on_hold' => '5',    // Banana (yellow)
        'uploaded' => '2',   // Sage (green)
        'completed' => '2',  // Sage (green)
        'editing' => '7',    // Peacock (cyan)
        'review' => '7',     // Peacock (cyan)
        'ready' => '10',     // Basil (dark green)
        'delivered' => '2',  // Sage (green)
        'cancelled' => '11', // Tomato (red)
        'declined' => '11',  // Tomato (red)
    ];

    /**
     * Default Google Calendar event colorId used when a shoot status is not present in
     * STATUS_COLOR_MAP ("9", Blueberry).
     */
    protected const DEFAULT_COLOR_ID = '9';

    public function __construct(
        protected ShootMutationSupportService $support
    ) {
    }

    public function build(Shoot $shoot, ?User $user = null): array
    {
        $shoot->loadMissing('services', 'serviceItems.service', 'serviceItems.unit', 'client', 'rep', 'units');

        $scheduledAt = $shoot->scheduled_at ?: $shoot->serviceItems
            ->pluck('scheduled_at')
            ->filter()
            ->sortBy(fn ($value) => $value->getTimestamp())
            ->first();

        if (!$scheduledAt) {
            throw new RuntimeException('Scheduled shoots are required for Google Calendar sync.');
        }

        $timezone = $this->calendarTimezone($shoot, $user);
        $start = $this->calendarStart($shoot, $scheduledAt, $timezone);
        // Req 4.1: end = start + booked-window duration. calculateShootDurationFromShoot()
        // always returns availability.default_shoot_duration_minutes (120 / 2h) so calendar
        // events match availability booked blocks and do not stretch toward max (240).
        $end = $start->copy()->addMinutes($this->support->calculateShootDurationFromShoot($shoot));

        return array_filter([
            'summary' => $this->buildTitle($shoot),
            'location' => $this->support->formatFullAddress($shoot),
            'description' => $this->buildDescription($shoot, $timezone),
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'colorId' => $this->resolveColorId($shoot),
            'reminders' => $this->buildReminders(),
            'extendedProperties' => [
                'private' => array_filter([
                    'repro_shoot_id' => (string) $shoot->id,
                    'repro_photographer_id' => $user?->id ? (string) $user->id : null,
                ]),
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public function buildForServiceItem(Shoot $shoot, ShootService $serviceItem, ?User $user = null): array
    {
        $serviceItem->loadMissing(['service', 'unit']);
        $scheduledAt = $serviceItem->scheduled_at ?: $shoot->scheduled_at;

        if (!$scheduledAt) {
            throw new RuntimeException('Scheduled service items are required for Google Calendar sync.');
        }

        $timezone = $this->calendarTimezone($shoot, $user);
        $start = $this->calendarStart($shoot, $scheduledAt, $timezone);
        $end = $start->copy()->addMinutes($this->calculateServiceItemDuration($serviceItem));

        return array_filter([
            'summary' => $this->buildServiceItemTitle($shoot, $serviceItem),
            'location' => $this->support->formatFullAddress($shoot),
            'description' => $this->buildServiceItemDescription($shoot, $serviceItem),
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
                    'repro_shoot_service_id' => (string) $serviceItem->id,
                    'repro_service_id' => (string) $serviceItem->service_id,
                    'repro_shoot_unit_id' => $serviceItem->shoot_unit_id ? (string) $serviceItem->shoot_unit_id : null,
                    'repro_photographer_id' => $user?->id ? (string) $user->id : null,
                ]),
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    protected function calendarTimezone(Shoot $shoot, ?User $user): string
    {
        foreach ([$user?->timezone, $shoot->timezone, config('app.timezone', 'UTC')] as $timezone) {
            $timezone = trim((string) $timezone);
            if ($timezone !== '') {
                return $timezone;
            }
        }

        return 'UTC';
    }

    protected function calendarStart(Shoot $shoot, Carbon $scheduledAt, string $timezone): Carbon
    {
        $start = $scheduledAt->copy();

        // Existing bookings without a shoot timezone store the selected local clock
        // in scheduled_at; the UTC model cast does not make it a UTC appointment.
        // Anchor that clock in the photographer's zone only at the Google boundary.
        // Explicitly zoned shoots already carry an absolute instant, so preserve it.
        return trim((string) $shoot->timezone) === ''
            ? $start->shiftTimezone($timezone)
            : $start->setTimezone($timezone);
    }

    protected function buildTitle(Shoot $shoot): string
    {
        $clientName = $this->clientName($shoot);

        return $this->isCancelled($shoot)
            ? "CANCELLED - {$clientName}"
            : $clientName;
    }

    /**
     * Req 6.1: map the lowercased shoot status to a Google Calendar event colorId via
     * STATUS_COLOR_MAP. Unknown/empty statuses fall back to the default colorId ("9"). The
     * returned value is always within Google's supported "1"-"11" range.
     */
    protected function resolveColorId(Shoot $shoot): string
    {
        $status = strtolower(trim((string) $shoot->status));

        return self::STATUS_COLOR_MAP[$status] ?? self::DEFAULT_COLOR_ID;
    }

    /**
     * Req 5.1: explicit popup reminders for the photographer calendar. useDefault is false
     * with overrides at 24 hours (1440 minutes) and 30 minutes before the start time. The
     * returned non-empty array survives build()'s scalar-emptiness array_filter.
     */
    protected function buildReminders(): array
    {
        return [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 24 * 60], // 24 hours
                ['method' => 'popup', 'minutes' => 30],      // 30 minutes
            ],
        ];
    }

    /**
     * Req 7.1/7.2: optional per-service "Service Timing:" block. The effective schedule for a
     * service item is its own scheduled_at, falling back to the shoot scheduled_at. When the
     * service items resolve to <= 1 distinct effective scheduled_at, the block is omitted
     * (null) so the description stays concise. Otherwise it renders one
     * "- {serviceName}: {time in $timezone}" line per service item. Never throws.
     */
    protected function buildPerServiceTimingBlock(Shoot $shoot, string $timezone): ?string
    {
        $shoot->loadMissing(['serviceItems.service', 'serviceItems.unit']);

        $items = $shoot->serviceItems;
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        $effective = $items->map(fn (ShootService $item) => $item->scheduled_at ?: $shoot->scheduled_at);

        $distinct = $effective
            ->filter()
            ->map(fn ($value) => $value->toIso8601String())
            ->unique();

        if ($distinct->count() <= 1) {
            return null;
        }

        $lines = $items
            ->map(function (ShootService $item) use ($shoot, $timezone) {
                $scheduledAt = $item->scheduled_at ?: $shoot->scheduled_at;
                if (!$scheduledAt) {
                    return null;
                }

                $serviceName = $this->formatServiceLabel((string) ($item->service?->name ?? 'Service'));
                if ($item->unit) $serviceName = $item->unit->label.' · '.$serviceName;
                if ($serviceName === '') {
                    $serviceName = 'Service';
                }

                $time = $this->calendarStart($shoot, $scheduledAt, $timezone)->format('D, M j Y g:i A');

                return "- {$serviceName}: {$time}";
            })
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            return null;
        }

        return "Service Timing:\n" . $lines->implode("\n");
    }

    /**
     * defined in the design "Description format specification". Sections are separated by a
     * single blank line. Client phone/email are never included (PII). The named sections
     * (Shoot Notes / Property Access / Arrival Instructions / On-Site Contact) always render,
     * falling back to "Not provided" when empty. Pricing, payment status, and internal note
     * columns (company_notes, editor_notes, admin_issue_notes) are never included.
     *
     * $timezone is threaded through to the per-service "Service Timing:" block so each
     * service time renders in the photographer's calendar timezone (Req 7).
     */
    protected function buildDescription(Shoot $shoot, string $timezone): ?string
    {
        // Contact block: client display name only. Client phone/email are PII and must
        // never appear in the photographer-facing Google Calendar description.
        $sections = [$this->clientName($shoot)];

        $repName = $this->repName($shoot);
        if ($repName !== '') {
            $sections[] = "Rep: {$repName}";
        }

        // Shoot Services: one normalized "- {label}" line per booked service item
        // (includes unit label when present). Falls back to the services relation.
        $serviceLabels = $this->shootServiceLabels($shoot);

        $servicesBlock = 'Shoot Services:';
        if ($serviceLabels->isNotEmpty()) {
            $servicesBlock .= "\n" . $serviceLabels->map(fn ($name) => "- {$name}")->implode("\n");
        }
        $sections[] = $servicesBlock;

        // Service Timing: optional per-service timing block. Present only when service items
        // carry more than one distinct effective scheduled_at (Req 7.1); otherwise omitted so
        // the description stays concise for standard shoots (Req 7.2).
        $serviceTimingBlock = $this->buildPerServiceTimingBlock($shoot, $timezone);
        if ($serviceTimingBlock !== null) {
            $sections[] = $serviceTimingBlock;
        }

        // Shoot Status: present only for cancelled shoots.
        if ($this->isCancelled($shoot)) {
            $sections[] = 'Shoot Status: Cancelled';
        }

        // Shoot Notes (customer-facing note text, or "Not provided").
        $shootNotes = $this->customerFacingNotes($shoot);
        $sections[] = "Shoot Notes:\n" . ($shootNotes !== '' ? $this->formatBodyText($shootNotes) : 'Not provided');

        // Property Access (derived, or "Not provided").
        $propertyAccess = $this->derivePropertyAccess($shoot);
        $sections[] = "Property Access:\n" . ($propertyAccess !== null ? $this->formatBodyText($propertyAccess) : 'Not provided');

        // Arrival Instructions (derived, or "Not provided").
        $arrivalInstructions = $this->deriveArrivalInstructions($shoot);
        $sections[] = "Arrival Instructions:\n" . ($arrivalInstructions !== null ? $this->formatBodyText($arrivalInstructions) : 'Not provided');

        // On-Site Contact (derived; client phone/email never included).
        $sections[] = "On-Site Contact:\n" . $this->deriveOnSiteContact($shoot);

        // Internal shoot link: always the last line.
        $sections[] = 'View shoot: ' . $this->buildShootUrl($shoot);

        return implode("\n\n", $sections);
    }

    protected function isCancelled(Shoot $shoot): bool
    {
        $status = strtolower(trim((string) $shoot->status));
        $workflowStatus = strtolower(trim((string) $shoot->workflow_status));

        return $status === Shoot::STATUS_CANCELLED
            || $workflowStatus === Shoot::STATUS_CANCELLED;
    }

    protected function clientName(Shoot $shoot): string
    {
        $client = $shoot->client;
        $name = trim((string) ($client?->name ?: $client?->company_name ?: ''));

        return $name !== '' ? $name : 'Client';
    }

    protected function clientPhone(Shoot $shoot): string
    {
        $client = $shoot->client;

        return trim((string) ($client?->phone ?: $client?->phonenumber ?: ''));
    }

    protected function clientEmail(Shoot $shoot): string
    {
        return trim((string) ($shoot->client?->email ?: ''));
    }

    /**
     * Property Access: prefer lockbox/combo + access notes from property_details,
     * then fall back to customer-facing shoot notes. Never throws.
     */
    protected function derivePropertyAccess(Shoot $shoot): ?string
    {
        $details = $this->propertyDetails($shoot);
        $lines = [];

        $presence = strtolower(trim((string) ($details['presenceOption'] ?? $details['presence_option'] ?? '')));
        $lockboxCode = trim((string) ($details['lockboxCode'] ?? $details['lockbox_code'] ?? ''));
        $lockboxLocation = trim((string) ($details['lockboxLocation'] ?? $details['lockbox_location'] ?? ''));
        $accessNotes = trim((string) ($details['accessNotes'] ?? $details['access_notes'] ?? ''));

        if ($presence !== '') {
            $presenceLabel = match ($presence) {
                'self' => 'Client will be present',
                'other' => 'Someone else will provide access',
                'lockbox' => 'Lockbox',
                default => ucfirst($presence),
            };
            $lines[] = $presenceLabel;
        }

        if ($lockboxCode !== '') {
            $lines[] = "Lockbox / combo code: {$lockboxCode}";
        }
        if ($lockboxLocation !== '') {
            $lines[] = "Lockbox location: {$lockboxLocation}";
        }
        if ($accessNotes !== '') {
            $lines[] = $accessNotes;
        }

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        $notes = $this->customerFacingNotes($shoot);

        return $notes !== '' ? $notes : null;
    }

    /**
     * Arrival Instructions: photographer_notes when present, otherwise fall back to the
     * customer-facing note text. Returns null when neither exists. Never throws.
     */
    protected function deriveArrivalInstructions(Shoot $shoot): ?string
    {
        $photographerNotes = trim((string) ($shoot->photographer_notes ?? ''));
        if ($photographerNotes !== '') {
            return $photographerNotes;
        }

        $notes = $this->customerFacingNotes($shoot);

        return $notes !== '' ? $notes : null;
    }

    /**
     * On-Site Contact: prefer access-contact fields from property_details when present,
     * otherwise fall back to the client display name only. Client phone/email never appear.
     * When the access contact phone matches the client phone, the phone is omitted (name ok).
     * Returns "Not provided" when no useful non-PII contact remains. Never throws.
     */
    protected function deriveOnSiteContact(Shoot $shoot): string
    {
        $propertyDetails = $this->propertyDetails($shoot);
        $accessName = trim((string) ($propertyDetails['accessContactName'] ?? $propertyDetails['access_contact_name'] ?? ''));
        $accessPhone = trim((string) ($propertyDetails['accessContactPhone'] ?? $propertyDetails['access_contact_phone'] ?? ''));
        $clientPhone = $this->clientPhone($shoot);

        if ($accessName !== '' || $accessPhone !== '') {
            // Drop access phone when it is the client's phone — same PII restriction.
            if ($accessPhone !== '' && $clientPhone !== '' && $this->phonesMatch($accessPhone, $clientPhone)) {
                $accessPhone = '';
            }

            if ($accessName !== '' && $accessPhone !== '') {
                return "{$accessName} ({$accessPhone})";
            }

            if ($accessName !== '') {
                return $accessName;
            }

            if ($accessPhone !== '') {
                return $accessPhone;
            }
            // Access block had only client-matching phone; fall through to client name.
        }

        $client = $shoot->client;
        $name = trim((string) ($client?->name ?: $client?->company_name ?: ''));

        return $name !== '' ? $name : 'Not provided';
    }

    /**
     * Compare phone numbers by digits only so formatting differences do not reintroduce
     * client PII into On-Site Contact.
     */
    protected function phonesMatch(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            return preg_replace('/\D+/', '', $value) ?? '';
        };

        $leftDigits = $normalize($left);
        $rightDigits = $normalize($right);

        return $leftDigits !== '' && $leftDigits === $rightDigits;
    }

    /**
     * First non-empty customer-facing note text (shoot_notes, then notes). Internal note
     * columns are never used. Returns an empty string when no text is available.
     */
    protected function customerFacingNotes(Shoot $shoot): string
    {
        return trim((string) ($shoot->shoot_notes ?: $shoot->notes ?: ''));
    }

    protected function buildShootUrl(Shoot $shoot): string
    {
        $base = rtrim((string) config('services.google.calendar.dashboard_url', 'https://reprodashboard.com'), '/');

        return $base . '/shoots/' . $shoot->id;
    }

    protected function buildServiceItemTitle(Shoot $shoot, ShootService $serviceItem): string
    {
        $serviceName = $this->formatServiceLabel((string) ($serviceItem->service?->name ?? 'Service'));
        if ($serviceItem->unit) $serviceName = $serviceItem->unit->label.' · '.$serviceName;

        return $serviceName !== ''
            ? $serviceName
            : $this->buildTitle($shoot);
    }

    protected function buildServiceItemDescription(Shoot $shoot, ShootService $serviceItem): ?string
    {
        $customerFacingNotes = trim((string) ($shoot->shoot_notes ?: $shoot->notes ?: ''));
        $photographerNotes = trim((string) ($shoot->photographer_notes ?: ''));
        $serviceName = $this->formatServiceLabel((string) ($serviceItem->service?->name ?? 'Service'));
        if ($serviceItem->unit) $serviceName = $serviceItem->unit->label.' · '.$serviceName;
        $sections = [];

        if ($serviceName !== '') {
            $sections[] = "Service\n" . $serviceName;
        }

        if ($customerFacingNotes !== '') {
            $sections[] = "Shoot Notes / Access Information\n" . $this->formatBodyText($customerFacingNotes);
        }

        if ($photographerNotes !== '') {
            $sections[] = "Photographer Notes\n" . $this->formatBodyText($photographerNotes);
        }

        if ($serviceItem->unit?->access_notes) {
            $sections[] = "Unit Access\n".$this->formatBodyText($serviceItem->unit->access_notes);
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }

    protected function calculateServiceItemDuration(ShootService $serviceItem): int
    {
        if ($serviceItem->duration_minutes) return (int) $serviceItem->duration_minutes;
        $defaultDurationMinutes = config('availability.default_shoot_duration_minutes', 120);
        $service = $serviceItem->relationLoaded('service') ? $serviceItem->service : $serviceItem->service()->first();

        if (!$service || !method_exists($service, 'getShootDurationMinutes')) {
            return $defaultDurationMinutes;
        }

        return $service->getShootDurationMinutes($serviceItem->unit?->sqft);
    }


    protected function shootServiceLabels(Shoot $shoot): \Illuminate\Support\Collection
    {
        $shoot->loadMissing(['serviceItems.service', 'serviceItems.unit', 'services']);

        $fromItems = $shoot->serviceItems
            ->reject(fn (ShootService $item) => in_array($item->workflow_status, [
                ShootService::WORKFLOW_CANCELLED,
            ], true))
            ->map(function (ShootService $item) {
                $serviceName = $this->formatServiceLabel((string) ($item->service?->name ?? 'Service'));
                if ($item->unit) {
                    $serviceName = $item->unit->label . ' · ' . $serviceName;
                }

                return $serviceName;
            })
            ->filter()
            ->values();

        if ($fromItems->isNotEmpty()) {
            return $fromItems;
        }

        return $shoot->services
            ->pluck('name')
            ->map(fn ($name) => $this->formatServiceLabel((string) $name))
            ->filter()
            ->values();
    }

    protected function propertyDetails(Shoot $shoot): array
    {
        $details = $shoot->property_details;

        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : [];
        }

        return is_array($details) ? $details : [];
    }

    protected function repName(Shoot $shoot): string
    {
        $shoot->loadMissing('rep');
        $rep = $shoot->rep;

        return trim((string) ($rep?->name ?: $rep?->company_name ?: ''));
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
