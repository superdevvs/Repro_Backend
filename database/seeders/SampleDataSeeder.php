<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Service;
use App\Models\Shoot;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $services = Service::all();
            if ($services->isEmpty()) {
                $services = collect([
                    Service::create([
                        'name' => 'Premium Photo Package',
                        'description' => 'Full interior/exterior photo set',
                        'category_id' => 1,
                        'base_price' => 325,
                        'delivery_time' => '2 business days',
                    ]),
                    Service::create([
                        'name' => 'Twilight Add-on',
                        'description' => 'Twilight hero shots',
                        'category_id' => 1,
                        'base_price' => 150,
                        'delivery_time' => '3 business days',
                    ]),
                ]);
            }

            $photographers = $this->createUsers('photographer', [
                ['name' => 'Alex Monroe', 'email' => 'alex.photographer@example.com'],
                ['name' => 'Dana Harper', 'email' => 'dana.photographer@example.com'],
                ['name' => 'Luis Grant', 'email' => 'luis.photographer@example.com'],
            ]);

            $clients = $this->createUsers('client', [
                ['name' => 'Riverstone Realty', 'email' => 'hello@riverstone.example.com'],
                ['name' => 'Northpoint Estates', 'email' => 'team@northpoint.example.com'],
                ['name' => 'Atlas Property Group', 'email' => 'atlas@property.example.com'],
                ['name' => 'Sierra Peaks Realty', 'email' => 'info@sierrapeaks.example.com'],
            ]);

            $admin = User::where('email', 'superadmin@example.com')->first();
            $createdBy = $admin?->name ?? 'System';

            $shootTemplates = [
                [
                    'address' => '1246 Brookstone Dr',
                    'city' => 'Bethesda',
                    'state' => 'MD',
                    'zip' => '20814',
                    'scheduled_date' => now()->addDay()->toDateString(),
                    'time' => '10:00 AM',
                    'base_quote' => 525,
                    'tax_amount' => 31.50,
                    'total_quote' => 556.50,
                    'workflow_status' => Shoot::WORKFLOW_BOOKED,
                ],
                [
                    'address' => '518 N Street NW',
                    'city' => 'Washington',
                    'state' => 'DC',
                    'zip' => '20001',
                    'scheduled_date' => now()->subDay()->toDateString(),
                    'time' => '14:00 PM',
                    'base_quote' => 675,
                    'tax_amount' => 40.50,
                    'total_quote' => 715.50,
                    'workflow_status' => Shoot::WORKFLOW_EDITING,
                ],
                [
                    'address' => '8229 Oak Shadow Way',
                    'city' => 'Fairfax',
                    'state' => 'VA',
                    'zip' => '22030',
                    'scheduled_date' => now()->subDays(3)->toDateString(),
                    'time' => '09:30 AM',
                    'base_quote' => 450,
                    'tax_amount' => 27.00,
                    'total_quote' => 477.00,
                    'workflow_status' => Shoot::STATUS_REQUESTED,
                ],
                [
                    'address' => '1103 Coastal View Dr',
                    'city' => 'Alexandria',
                    'state' => 'VA',
                    'zip' => '22314',
                    'scheduled_date' => now()->subWeek()->toDateString(),
                    'time' => '11:45 AM',
                    'base_quote' => 590,
                    'tax_amount' => 35.40,
                    'total_quote' => 625.40,
                    'workflow_status' => Shoot::WORKFLOW_ADMIN_VERIFIED,
                ],
            ];

            foreach ($shootTemplates as $index => $template) {
                $client = $clients[$index % count($clients)];
                $photographer = $photographers[$index % count($photographers)];
                $service = $services[$index % $services->count()];

                Shoot::create([
                    'client_id' => $client->id,
                    'photographer_id' => $photographer->id,
                    'service_id' => $service->id,
                    'address' => $template['address'],
                    'city' => $template['city'],
                    'state' => $template['state'],
                    'zip' => $template['zip'],
                    'scheduled_date' => $template['scheduled_date'],
                    'time' => $template['time'],
                    'base_quote' => $template['base_quote'],
                    'tax_amount' => $template['tax_amount'],
                    'total_quote' => $template['total_quote'],
                    'payment_status' => 'paid',
                    'payment_type' => 'square',
                    'notes' => 'Imported sample booking',
                    'status' => $template['workflow_status'] === Shoot::WORKFLOW_ADMIN_VERIFIED ? 'completed' : 'booked',
                    'workflow_status' => $template['workflow_status'],
                    'created_by' => $createdBy,
                ]);
            }
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function createUsers(string $role, array $records)
    {
        return collect($records)->map(function ($data) use ($role) {
            return User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('Password123!'),
                    'role' => $role,
                    'account_status' => 'active',
                    'phonenumber' => $data['phone'] ?? '2025550' . random_int(100, 999),
                    'company_name' => $data['company'] ?? ($data['name'] . ' Co.'),
                    'created_by_name' => 'Seeder',
                    'username' => Str::slug($data['name']) . random_int(100, 999),
                ]
            );
        });
    }
}
