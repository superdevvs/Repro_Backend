<?php

namespace Tests\MySql;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AryeoCascadeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    public static function deletedParents(): array
    {
        return ['service' => ['services'], 'category' => ['categories'], 'client' => ['users']];
    }

    private function fixture(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create();
        $first = Shoot::withoutEvents(fn () => Shoot::factory()->create(['client_id' => $client->id, 'service_id' => $service->id]));
        $second = Shoot::withoutEvents(fn () => Shoot::factory()->create(['client_id' => $client->id, 'service_id' => null]));
        $line = DB::table('shoot_service')->insertGetId(['shoot_id' => $second->id, 'service_id' => $service->id]);

        return [$client, $service, $first, $second, $line];
    }

    #[DataProvider('deletedParents')]
    public function test_direct_and_transitive_cascades_keep_each_affected_shoot_in_the_feed(string $table): void
    {
        [$client, $service, $first, $second] = $this->fixture();
        $id = match ($table) { 'users' => $client->id, 'categories' => $service->category_id, default => $service->id };
        $after = DB::table('aryeo_changes')->max('id') ?? 0;
        $this->assertSame(1, DB::table($table)->where('id', $id)->delete());
        $changed = DB::table('aryeo_changes')->where('id', '>', $after)->pluck('shoot_id')->all();
        $this->assertContains($first->id, $changed);
        $this->assertContains($second->id, $changed);
    }

    public function test_deleting_an_invoice_only_payment_notifies_its_allocated_shoot(): void
    {
        [, , , $second, $line] = $this->fixture();
        $payment = DB::table('payments')->insertGetId(['shoot_id' => null, 'amount' => 1, 'currency' => 'USD', 'status' => 'completed']);
        DB::table('payment_service_allocations')->insert(['payment_id' => $payment, 'shoot_service_id' => $line, 'amount' => 1]);
        $after = DB::table('aryeo_changes')->max('id') ?? 0;
        DB::table('payments')->where('id', $payment)->delete();
        $this->assertContains($second->id, DB::table('aryeo_changes')->where('id', '>', $after)->pluck('shoot_id')->all());
        $this->assertDatabaseMissing('payment_service_allocations', ['payment_id' => $payment]);
    }

    public function test_set_null_payment_cascade_notifies_the_allocated_shoot_without_deleting_the_payment(): void
    {
        [$client, , , $second, $line] = $this->fixture();
        $invoice = DB::table('invoices')->insertGetId(['user_id' => $client->id, 'role' => 'client', 'period_start' => '2026-11-01', 'period_end' => '2026-11-07']);
        $payment = DB::table('payments')->insertGetId(['shoot_id' => null, 'invoice_id' => $invoice, 'amount' => 1, 'currency' => 'USD', 'status' => 'completed']);
        DB::table('payment_service_allocations')->insert(['payment_id' => $payment, 'shoot_service_id' => $line, 'amount' => 1]);
        $after = DB::table('aryeo_changes')->max('id') ?? 0;
        DB::table('invoices')->where('id', $invoice)->delete();
        $this->assertContains($second->id, DB::table('aryeo_changes')->where('id', '>', $after)->pluck('shoot_id')->all());
        $this->assertDatabaseHas('payments', ['id' => $payment, 'invoice_id' => null]);
    }
}
