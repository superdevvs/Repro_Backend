<?php

namespace Tests\Unit\Support;

use App\Support\InvoiceReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceReferenceTest extends TestCase
{
    public static function references(): array
    {
        return [
            'stored label' => ['Invoice 00018', null, '00018', 'Invoice 00018'],
            'stored label and hash' => ['Invoice #18', null, '#18', 'Invoice #18'],
            'bare padded number' => ['00018', null, '00018', 'Invoice 00018'],
            'legacy INV reference' => ['INV-1001', null, 'INV-1001', 'Invoice INV-1001'],
            'hyphenated invoice identifier' => ['INVOICE-1001', null, 'INVOICE-1001', 'Invoice INVOICE-1001'],
            'label without number uses id' => ['Invoice', 18, '#18', 'Invoice #18'],
            'label without number or id stays empty' => ['Invoice', null, '', ''],
            'missing number with id' => [null, 18, '#18', 'Invoice #18'],
            'missing number and id' => [null, null, '', ''],
        ];
    }

    #[DataProvider('references')]
    public function test_it_normalizes_stored_references_without_repeating_the_label(
        mixed $value,
        mixed $fallbackId,
        string $expectedNumber,
        string $expectedLabel
    ): void {
        $this->assertSame($expectedNumber, InvoiceReference::number($value, $fallbackId));
        $this->assertSame($expectedLabel, InvoiceReference::label($value, $fallbackId));
    }
}
