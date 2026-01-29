<?php

namespace Database\Seeders;

use App\Models\SmsNumber;
use Illuminate\Database\Seeder;

class MightyCallSmsNumbersSeeder extends Seeder
{
    /**
     * Seed the MightyCall SMS numbers.
     * 
     * These are the configured phone numbers with their MightyCall API keys.
     */
    public function run(): void
    {
        $numbers = [
            [
                'phone_number' => '+12028681663',
                'label' => 'Main Phone',
                'mighty_call_key' => '60db9a3d9930',
                'is_default' => false,
            ],
            [
                'phone_number' => '+12027803332',
                'label' => 'Editor Account',
                'mighty_call_key' => '3847a1cf2d34',
                'is_default' => false,
            ],
            [
                'phone_number' => '+18886567627',
                'label' => 'Dashboard Texting',
                'mighty_call_key' => '21ebcff6ba39',
                'is_default' => true,
            ],
        ];

        foreach ($numbers as $numberData) {
            SmsNumber::updateOrCreate(
                ['phone_number' => $numberData['phone_number']],
                $numberData
            );

            $this->command->info("Seeded SMS number: {$numberData['label']} ({$numberData['phone_number']})");
        }

        $this->command->info('MightyCall SMS numbers seeded successfully!');
    }
}
