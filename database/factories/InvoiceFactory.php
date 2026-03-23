<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $issueDate = Carbon::instance($this->faker->dateTimeBetween('-2 months', 'now'));
        $dueDate = (clone $issueDate)->addDays($this->faker->numberBetween(7, 30));
        $subtotal = $this->faker->randomFloat(2, 100, 2000);
        $tax = $this->faker->randomFloat(2, 0, 200);

        $attributes = [
            'user_id' => User::factory(),
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => $issueDate->copy()->toDateString(),
            'period_end' => $dueDate->copy()->toDateString(),
            'shoot_id' => Shoot::factory(),
            'client_id' => User::factory(),
            'invoice_number' => strtoupper('INV-' . Str::random(8)),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'status' => 'sent',
            'paid_at' => null,
        ];

        if (Schema::hasColumn('invoices', 'notes')) {
            $attributes['notes'] = null;
        }

        return $attributes;
    }
}
