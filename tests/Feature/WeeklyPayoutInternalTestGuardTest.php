<?php

namespace Tests\Feature;

use App\Models\EditorPayout;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\User;
use App\Services\EditorPayoutService;
use App\Services\PayoutReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyPayoutInternalTestGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_test_work_is_excluded_from_all_payout_email_data_and_editor_sync(): void
    {
        $start = Carbon::parse('2026-09-20')->startOfDay();
        $end = Carbon::parse('2026-09-26')->endOfDay();
        $photographer = User::factory()->create(['role' => 'photographer']);
        $rep = User::factory()->create(['role' => 'salesRep', 'metadata' => ['repDetails' => ['commissionPercentage' => 15]]]);
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['photo_edit_rate' => 25]]);
        $service = Service::factory()->create(['name' => '1 Photo', 'photo_count' => 1, 'price' => 200, 'photographer_pay' => 75, 'exclude_from_sales_commission' => false]);
        $shoots = collect();
        foreach ([Shoot::SHOOT_TYPE_STANDARD, Shoot::SHOOT_TYPE_INTERNAL_TEST, Shoot::SHOOT_TYPE_INTERNAL_TEST] as $type) {
            $shoot = Shoot::factory()->create([
                'shoot_type' => $type, 'photographer_id' => $photographer->id, 'rep_id' => $rep->id,
                'sales_rep_pay_enabled' => true, 'workflow_status' => Shoot::WORKFLOW_COMPLETED,
                'scheduled_date' => '2026-09-22', 'completed_at' => '2026-09-22 12:00:00',
            ]);
            $shoot->services()->attach($service->id, [
                'price' => 200, 'quantity' => 1, 'photographer_pay' => 75, 'photographer_id' => $photographer->id,
                'editor_id' => $editor->id, 'editing_completed_at' => '2026-09-22 12:00:00',
            ]);
            $shoots->push($shoot);
        }
        // Keep historical rows intact, but do not include them in real reports.
        $historical = EditorPayout::create([
            'editor_id' => $editor->id, 'shoot_id' => $shoots[1]->id, 'service_id' => $service->id,
            'service_name' => '1 Photo', 'quantity_snapshot' => 1, 'rate_snapshot' => 999,
            'payout_amount' => 999, 'completed_at' => '2026-09-22 12:00:00', 'is_paid' => true,
        ]);
        foreach ([ShootCompensation::RECIPIENT_PHOTOGRAPHER => $photographer, ShootCompensation::RECIPIENT_SALES_REP => $rep] as $type => $recipient) {
            ShootCompensation::create([
                'shoot_id' => $shoots[1]->id, 'scope_key' => 'shoot', 'recipient_type' => $type,
                'recipient_user_id' => $recipient->id, 'mode' => 'custom', 'amount' => 999, 'earned_at' => '2026-09-22 12:00:00',
                'reason_code' => 'test_fixture', 'policy_version' => '1',
            ]);
        }

        $payouts = app(PayoutReportService::class);
        $this->assertSame(75.0, $payouts->buildPhotographerSummaries($start, $end)->sole()['gross_total']);
        $this->assertSame(30.0, $payouts->buildSalesRepSummaries($start, $end)->sole()['payout_total']);
        $this->assertSame(25.0, app(EditorPayoutService::class)->buildEmailSummaries($start, $end)->sole()['gross_total']);
        $this->assertDatabaseMissing('editor_payouts', ['shoot_id' => $shoots[2]->id]);
        $this->assertDatabaseHas('editor_payouts', ['id' => $historical->id, 'payout_amount' => 999]);
        $this->assertSame(2, EditorPayout::count());
    }
}
