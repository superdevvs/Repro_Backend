<?php

namespace App\Services;

class ShootTaxService
{
    // Tax rates by region
    private const TAX_RATES = [
        'md' => 6.0,  // Maryland
        'dc' => 5.75, // District of Columbia
        'va' => 5.3,  // Virginia
        'none' => 0.0,
    ];

    /**
     * Determine tax region from state code
     */
    public function determineTaxRegion(string $state): string
    {
        $state = strtolower(trim($state));
        
        $stateMap = [
            'maryland' => 'md',
            'md' => 'md',
            'district of columbia' => 'dc',
            'dc' => 'dc',
            'washington dc' => 'dc',
            'virginia' => 'va',
            'va' => 'va',
        ];

        return $stateMap[$state] ?? 'none';
    }

    /**
     * Get tax percentage for a region
     */
    public function getTaxPercent(string $taxRegion): float
    {
        return self::TAX_RATES[strtolower($taxRegion)] ?? 0.0;
    }

    /**
     * Calculate tax amount
     */
    public function calculateTax(float $baseAmount, string $taxRegion): float
    {
        $taxPercent = $this->getTaxPercent($taxRegion);
        return round($baseAmount * ($taxPercent / 100), 2);
    }

    /**
     * Calculate total with tax
     */
    public function calculateTotal(float $baseAmount, string $taxRegion): array
    {
        $taxPercent = $this->getTaxPercent($taxRegion);
        $taxAmount = $this->calculateTax($baseAmount, $taxRegion);
        $total = round($baseAmount + $taxAmount, 2);

        return [
            'base_quote' => $baseAmount,
            'tax_region' => $taxRegion,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'total_quote' => $total,
        ];
    }
}

