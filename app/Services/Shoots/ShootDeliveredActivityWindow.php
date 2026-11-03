<?php

namespace App\Services\Shoots;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Opt-in delivery instants, independent of booked dates and unrelated updates. */
final class ShootDeliveredActivityWindow
{
    private function __construct(private DateTimeImmutable $from, private DateTimeImmutable $to) {}

    public static function fromRequest(Request $request): ?self
    {
        if (! $request->query->has('delivered_activity_from') && ! $request->query->has('delivered_activity_to')) {
            return null;
        }

        $from = self::parseISO($request->query('delivered_activity_from'));
        $to = self::parseISO($request->query('delivered_activity_to'));
        $errors = [];
        foreach (['delivered_activity_from' => $from, 'delivered_activity_to' => $to] as $key => $value) {
            if ($value === null) {
                $errors[$key] = 'Both delivery activity bounds must be ISO date-times with seconds and an explicit Z or ±HH:mm timezone.';
            }
        }
        if ($from && $to && $from > $to) {
            $errors['delivered_activity_to'] = 'Delivery activity end must be at or after its start.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($from, $to);
    }

    public function apply(Builder $query): void
    {
        // SQL UTC timestamps and ISO offsets coexist in imported records. SQLite
        // date functions round fractions to milliseconds. Like ShootListOrdering,
        // project only authorized scalar keys and compare exact instants in PHP.
        $projection = (clone $query)->withoutEagerLoads()->reorder()
            ->select(['shoots.id', 'shoots.admin_verified_at', 'shoots.completed_at'])->toBase();
        $projection->limit = null;
        $projection->offset = null;
        $ids = [];
        foreach ($projection->cursor() as $row) {
            $primary = is_string($row->admin_verified_at) ? trim($row->admin_verified_at) : '';
            $raw = $primary !== '' ? $primary : $row->completed_at;
            $instant = self::parseStored($raw);
            if ($instant !== null && $instant >= $this->from && $instant <= $this->to) {
                $ids[] = (int) $row->id;
            }
        }
        // Integer literals avoid SQLite's bound-parameter limit for large teams.
        // All existing role, status and operational scopes remain on the query.
        $query->whereIntegerInRaw('shoots.id', $ids);
    }

    public function metadata(): array
    {
        return ['from' => $this->from->format('Y-m-d\TH:i:s.u\Z'), 'to' => $this->to->format('Y-m-d\TH:i:s.u\Z')];
    }

    private static function parseISO(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
            $value, $parts
        )) {
            return null;
        }
        $normalized = $parts[1].'.'.str_pad($parts[2] ?? '', 6, '0').($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        return $date && $date->format('Y-m-d\TH:i:s.uP') === $normalized
            ? $date->setTimezone(new DateTimeZone('UTC')) : null;
    }

    private static function parseStored(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        if ($iso = self::parseISO($value)) {
            return $iso;
        }
        // Eloquent delivery writes now() in the application timezone (UTC in
        // production). Raw reads avoid casts that could discard stored offsets.
        if (! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $value, $parts)) {
            return null;
        }
        $normalized = $parts[1].'.'.str_pad($parts[2] ?? '', 6, '0');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, new DateTimeZone(config('app.timezone', 'UTC')));
        return $date && $date->format('Y-m-d H:i:s.u') === $normalized
            ? $date->setTimezone(new DateTimeZone('UTC')) : null;
    }
}
