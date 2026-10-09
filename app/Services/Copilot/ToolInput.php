<?php

namespace App\Services\Copilot;

use Illuminate\Validation\ValidationException;

/** Validate the published schema on the server; model/UI schema compliance is not trusted. */
final class ToolInput
{
    public function validate(mixed $value, array $schema, string $path = 'arguments'): void
    {
        $type = $schema['type'];
        $valid = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value), 'string' => is_string($value),
            'integer' => is_int($value), 'boolean' => is_bool($value), default => false,
        };
        if (! $valid) {
            $this->fail($path, 'Expected '.$type.'.');
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->fail($path, 'Unsupported value.');
        }
        if ($type === 'object') {
            $properties = (array) $schema['properties'];
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $this->fail($path.'.'.$key, 'Required field.');
                }
            }
            foreach ($value as $key => $child) {
                if (! array_key_exists($key, $properties)) {
                    $this->fail($path.'.'.$key, 'Unknown field.');
                }
                $this->validate($child, $properties[$key], $path.'.'.$key);
            }
        } elseif ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 100)) {
                $this->fail($path, 'Invalid number of items.');
            }
            foreach ($value as $index => $child) {
                $this->validate($child, $schema['items'], $path.'.'.$index);
            }
        } elseif ($type === 'string') {
            $length = mb_strlen($value);
            if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? 1000)) {
                $this->fail($path, 'Invalid text length.');
            }
            if (($schema['format'] ?? '') === 'uuid' && ! \Illuminate\Support\Str::isUuid($value)) {
                $this->fail($path, 'Invalid ID.');
            }
            if (($schema['format'] ?? '') === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $this->fail($path, 'Use YYYY-MM-DD.');
            }
            if (($schema['format'] ?? '') === 'date') {
                [$year, $month, $day] = array_map('intval', explode('-', $value));
                if (! checkdate($month, $day, $year)) {
                    $this->fail($path, 'Invalid calendar date.');
                }
            }
        } elseif ($type === 'integer' && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
            $this->fail($path, 'Value is outside the allowed range.');
        }
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
