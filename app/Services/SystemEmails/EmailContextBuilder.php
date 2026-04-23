<?php

namespace App\Services\SystemEmails;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use JsonSerializable;
use stdClass;

class EmailContextBuilder
{
    public function __construct(
        private readonly EmailBrandingConfig $brandingConfig,
    ) {
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    public function build(array $sections = []): array
    {
        return [
            'recipient' => $this->normalizeSection($sections['recipient'] ?? []),
            'account' => $this->normalizeSection($sections['account'] ?? []),
            'shoot' => $this->normalizeSection($sections['shoot'] ?? []),
            'invoice' => $this->normalizeSection($sections['invoice'] ?? []),
            'payment' => $this->normalizeSection($sections['payment'] ?? []),
            'links' => $this->normalizeSection($sections['links'] ?? []),
            'branding' => $this->normalizeSection($this->brandingConfig->defaults((array) ($sections['branding'] ?? []))),
            'meta' => $this->normalizeSection($sections['meta'] ?? []),
        ];
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public function normalizeSection(mixed $value): mixed
    {
        if ($value instanceof Fluent) {
            $value = $value->toArray();
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->normalizeSection($item))->all();
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof Model) {
            $attributes = $value->attributesToArray();

            foreach ($value->getRelations() as $relation => $related) {
                $attributes[$relation] = $this->normalizeSection($related);
            }

            $value = $attributes;
        } elseif (is_object($value) && !($value instanceof stdClass)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->normalizeSection($item), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeSection($item);
            }

            return $normalized;
        }

        return $value;
    }
}
