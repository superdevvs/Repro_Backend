<?php

namespace App\Http\Resources\Messaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class SmsContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $numbers = collect($this->phones_json ?? [])
            ->map(function ($entry, $index) {
                if (!is_array($entry)) {
                    return null;
                }

                return [
                    'id' => $entry['id'] ?? ($this->id.'-'.$index),
                    'number' => $entry['number'] ?? null,
                    'label' => $entry['label'] ?? null,
                    'is_primary' => (bool) ($entry['is_primary'] ?? false),
                ];
            })
            ->filter(fn ($entry) => filled($entry['number'] ?? null))
            ->values()
            ->all();

        if ($this->phone && empty($numbers)) {
            $numbers[] = [
                'id' => $this->id.'-primary',
                'number' => $this->phone,
                'label' => 'Main',
                'is_primary' => true,
            ];
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'initials' => $this->initials(),
            'type' => $this->type ?? 'contact',
            'email' => $this->email,
            'primaryNumber' => $this->phone ?? ($numbers[0]['number'] ?? null),
            'numbers' => $numbers,
            'comment' => $this->comment,
            'tags' => $this->tags_json ?? [],
        ];
    }

    protected function initials(): string
    {
        if (!$this->name) {
            return '??';
        }

        $parts = preg_split('/\s+/', trim($this->name));
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return Str::limit($initials ?: Str::upper(Str::substr($this->name, 0, 1)), 2, '');
    }
}

