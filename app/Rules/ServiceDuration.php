<?php

namespace App\Rules;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** Zero is a non-capture sentinel, never a way to remove onsite conflict checks. */
class ServiceDuration implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((int) $value >= 5) {
            return;
        }

        $row = data_get($this->data, substr($attribute, 0, strrpos($attribute, '.')), []);
        $serviceId = $row['service_id'] ?? $row['id'] ?? null;
        if (! $serviceId && isset($row['shoot_service_id'])) {
            $serviceId = \App\Models\ShootService::find($row['shoot_service_id'])?->service_id;
        }
        $service = Service::find($serviceId);
        if ((int) $value === 0 && $service && ! $service->requiresPhotographer()) {
            return;
        }

        $fail('Onsite duration must be at least 5 minutes. Zero is only available for services without onsite capture.');
    }
}
