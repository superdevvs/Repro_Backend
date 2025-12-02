<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Resources\Messaging\SmsContactResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsContactController extends Controller
{
    public function update(Contact $contact, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'type' => ['nullable', 'string', 'max:50'],
            'numbers' => ['nullable', 'array', 'max:6'],
            'numbers.*.id' => ['nullable', 'string'],
            'numbers.*.number' => ['required_with:numbers', 'string'],
            'numbers.*.label' => ['nullable', 'string', 'max:50'],
            'numbers.*.is_primary' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:30'],
        ]);

        $numbers = collect($data['numbers'] ?? [])
            ->map(function ($entry) {
                return [
                    'id' => $entry['id'] ?? null,
                    'number' => $entry['number'],
                    'label' => $entry['label'] ?? null,
                    'is_primary' => (bool) ($entry['is_primary'] ?? false),
                ];
            })
            ->filter(fn ($entry) => filled($entry['number']))
            ->values()
            ->all();

        if (!empty($numbers)) {
            $primary = collect($numbers)->firstWhere('is_primary', true);
            if (!$primary && $contact->phone) {
                $numbers[] = [
                    'id' => $contact->id.'-primary',
                    'number' => $contact->phone,
                    'label' => 'Main',
                    'is_primary' => true,
                ];
            }
        }

        $contact->fill([
            'name' => $data['name'] ?? $contact->name,
            'email' => $data['email'] ?? $contact->email,
            'type' => $data['type'] ?? $contact->type,
            'phones_json' => !empty($numbers) ? $numbers : null,
            'tags_json' => $data['tags'] ?? $contact->tags_json,
        ])->save();

        if (!empty($numbers)) {
            $primaryNumber = collect($numbers)->firstWhere('is_primary', true)
                ?? $numbers[0]
                ?? null;

            if ($primaryNumber) {
                $contact->phone = $primaryNumber['number'];
                $contact->save();
            }
        }

        return response()->json([
            'contact' => SmsContactResource::make($contact->refresh()),
        ]);
    }

    public function updateComment(Contact $contact, Request $request): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        $contact->update(['comment' => $data['comment'] ?? null]);

        return response()->json([
            'contact' => SmsContactResource::make($contact->refresh()),
        ]);
    }
}

