<?php

namespace App\Services\Shoots;

use App\Models\CompReshootItem;
use App\Models\ShootCompensation;

class ComplimentaryReshootReasonPolicy
{
    public const VERSION = 'comp-reshoot-v1';

    public const MISSED_AREA = 'missed_area';

    public const QUALITY_CORRECTION = 'quality_correction';

    public const COMPANY_ERROR = 'company_error';

    public const CLIENT_ACCOMMODATION = 'client_accommodation';

    public const WEATHER_ACCESS = 'weather_access';

    public const OTHER = 'other';

    public const REASONS = [
        self::MISSED_AREA,
        self::QUALITY_CORRECTION,
        self::COMPANY_ERROR,
        self::CLIENT_ACCOMMODATION,
        self::WEATHER_ACCESS,
        self::OTHER,
    ];

    public function definitions(): array
    {
        return [
            self::MISSED_AREA => [
                'label' => 'Missed area or room',
                'responsibility' => CompReshootItem::RESPONSIBILITY_PHOTOGRAPHER,
                'photographer_mode' => ShootCompensation::MODE_NONE,
                'sales_rep_mode' => ShootCompensation::MODE_NONE,
            ],
            self::QUALITY_CORRECTION => [
                'label' => 'Quality correction',
                'responsibility' => CompReshootItem::RESPONSIBILITY_PHOTOGRAPHER,
                'photographer_mode' => ShootCompensation::MODE_NONE,
                'sales_rep_mode' => ShootCompensation::MODE_NONE,
            ],
            self::COMPANY_ERROR => [
                'label' => 'Company error',
                'responsibility' => CompReshootItem::RESPONSIBILITY_COMPANY,
                'photographer_mode' => ShootCompensation::MODE_STANDARD,
                'sales_rep_mode' => ShootCompensation::MODE_NONE,
            ],
            self::CLIENT_ACCOMMODATION => [
                'label' => 'Client accommodation',
                'responsibility' => CompReshootItem::RESPONSIBILITY_CLIENT,
                'photographer_mode' => ShootCompensation::MODE_STANDARD,
                'sales_rep_mode' => ShootCompensation::MODE_NONE,
            ],
            self::WEATHER_ACCESS => [
                'label' => 'Weather or property access',
                'responsibility' => CompReshootItem::RESPONSIBILITY_WEATHER_ACCESS,
                'photographer_mode' => ShootCompensation::MODE_STANDARD,
                'sales_rep_mode' => ShootCompensation::MODE_NONE,
            ],
            self::OTHER => [
                'label' => 'Other',
                'responsibility' => null,
                'photographer_mode' => null,
                'sales_rep_mode' => null,
            ],
        ];
    }

    public function definition(string $reasonCode): array
    {
        return $this->definitions()[$reasonCode] ?? $this->definitions()[self::OTHER];
    }

    public function suggestedMode(string $reasonCode, string $recipientType): ?string
    {
        $key = $recipientType === ShootCompensation::RECIPIENT_SALES_REP
            ? 'sales_rep_mode'
            : 'photographer_mode';

        return $this->definition($reasonCode)[$key] ?? null;
    }

    public function suggestedResponsibility(string $reasonCode): ?string
    {
        return $this->definition($reasonCode)['responsibility'] ?? null;
    }

    public function requiresExplicitSalesRepChoice(string $reasonCode): bool
    {
        return in_array($reasonCode, [self::CLIENT_ACCOMMODATION, self::OTHER], true);
    }

    public function options(): array
    {
        return collect($this->definitions())
            ->map(fn (array $definition, string $code) => [
                'code' => $code,
                'label' => $definition['label'],
                'suggested_responsibility' => $definition['responsibility'],
                'suggested_photographer_mode' => $definition['photographer_mode'],
                'suggested_sales_rep_mode' => $definition['sales_rep_mode'],
                'requires_note' => $code === self::OTHER,
                'requires_explicit_compensation' => $code === self::OTHER,
                'requires_explicit_sales_rep_choice' => $this->requiresExplicitSalesRepChoice($code),
            ])
            ->values()
            ->all();
    }
}
