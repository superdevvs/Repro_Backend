<?php

namespace App\Services;

use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pure matching logic for photographer service-area assignment (Req 10).
 *
 * This service performs NO I/O: it filters an already-loaded collection of
 * photographers against a service-area filter, relying on each photographer's
 * eager-loaded `serviceAreas` relation. Keeping it side-effect free makes the
 * matching logic directly unit- and property-testable, and lets `preview` and
 * `commit` share the exact same match path (Req 10.2, 10.3, 10.5).
 */
class ServiceAreaMatcher
{
    /**
     * Return the photographers whose loaded service areas contain an entry
     * matching the given filter on (kind, case-insensitive value).
     *
     * @param  Collection<int, User>  $photographers  photographers with `serviceAreas` loaded
     * @param  array{kind: string, value: string}  $filter  e.g. ['kind' => 'state', 'value' => 'MD']
     * @return Collection<int, User>
     */
    public function match(Collection $photographers, array $filter): Collection
    {
        ['kind' => $kind, 'value' => $value] = $this->normalizeFilter($filter);

        return $photographers
            ->filter(fn (User $photographer) => $this->photographerMatches($photographer, $kind, $value))
            ->values();
    }

    /**
     * Whether a single photographer's service areas satisfy the (kind, value) filter.
     */
    private function photographerMatches(User $photographer, string $kind, string $value): bool
    {
        return $photographer->serviceAreas->contains(
            fn (ServiceArea $area) => $area->kind === $kind
                && strcasecmp((string) $area->value, $value) === 0
        );
    }

    /**
     * Validate and normalize the incoming filter.
     *
     * @param  array{kind?: string, value?: string}  $filter
     * @return array{kind: string, value: string}
     */
    private function normalizeFilter(array $filter): array
    {
        $kind = $filter['kind'] ?? null;
        $value = $filter['value'] ?? null;

        if (! is_string($kind) || $kind === '') {
            throw new InvalidArgumentException('Service-area filter requires a non-empty "kind".');
        }

        if (! in_array($kind, ServiceArea::KINDS, true)) {
            throw new InvalidArgumentException("Unknown service-area kind [{$kind}].");
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Service-area filter requires a non-empty "value".');
        }

        return ['kind' => $kind, 'value' => $value];
    }
}
