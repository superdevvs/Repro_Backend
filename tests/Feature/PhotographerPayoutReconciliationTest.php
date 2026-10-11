<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PayoutReportService;
use App\Services\Invoices\PhotographerPayoutReconciliation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotographerPayoutReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function work(User $person, string $date='2026-10-10', string $state='uploaded', float $pay=78.75): Shoot
    {
        $service=Service::factory()->create(['name'=>'HDR or floorplan capture','price'=>175,'photographer_pay'=>$pay,'pricing_type'=>'fixed']);
        $shoot=Shoot::factory()->create(['photographer_id'=>$person->id,'scheduled_date'=>$date,'shoot_type'=>'standard','status'=>$state,'workflow_status'=>$state,'photos_uploaded_at'=>$state==='scheduled'?null:'2026-10-10 23:40:00','completed_at'=>null,'admin_verified_at'=>null]);
        $shoot->services()->attach($service->id,['price'=>175,'quantity'=>1,'photographer_id'=>$person->id]);
        return $shoot;
    }

    private function generate(): \Illuminate\Support\Collection
    {
        return app(InvoiceService::class)->generateForPeriod(Carbon::parse('2026-10-04'),Carbon::parse('2026-10-10'),false);
    }

    public function test_uploaded_photography_and_floorplans_are_earned_before_delivery(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $floor=Service::factory()->create(['name'=>'Floorplan capture','price'=>50,'photographer_pay'=>22.5,'pricing_type'=>'fixed']);
        $shoot->services()->attach($floor->id,['price'=>50,'quantity'=>1,'photographer_id'=>$person->id]);
        $invoice=$this->generate()->sole();
        $this->assertSame(101.25,(float)$invoice->total_amount);
        $this->assertCount(2,$invoice->items);
        $this->assertNull($shoot->fresh()->completed_at);
        $this->assertSame('pending',$invoice->approval_status);
        $this->assertSame(0.0,(float)$invoice->amount_paid);
        $this->assertSame(101.25,app(PayoutReportService::class)->buildPhotographerSummaries(Carbon::parse('2026-10-04'),Carbon::parse('2026-10-10')->endOfDay())->sole()['gross_total']);
    }

    public function test_booking_snapshot_survives_catalog_changes_and_zero_override(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $service=$shoot->services()->first();
        $service->update(['photographer_pay'=>150,'name'=>'Changed catalogue']);
        $invoice=$this->generate()->sole();
        $this->assertSame(78.75,(float)$invoice->total_amount);
        $this->assertSame('HDR or floorplan capture',$invoice->items->sole()->meta['payout_basis']['service_name']);
        $other=$this->work($person);
        $other->services()->updateExistingPivot($other->services()->first()->id,['photographer_pay'=>0]);
        $this->generate();
        $this->assertSame(78.75,(float)$invoice->fresh()->total_amount);
    }

    public function test_pending_work_is_explicit_and_not_in_invoice_amount(): void
    {
        $person=User::factory()->photographer()->create();
        $this->work($person);
        $this->work($person,'2026-10-10','scheduled',56.25);
        $invoice=$this->generate()->sole();
        $review=app(PhotographerPayoutReconciliation::class)->review($invoice);
        $this->assertSame(1,$review['pending_count']);
        $this->assertSame(56.25,$review['pending_total']);
        $summary=app(PayoutReportService::class)->buildPhotographerSummaries(Carbon::parse('2026-10-04'),Carbon::parse('2026-10-10')->endOfDay())->sole();
        $this->assertSame(78.75,$summary['gross_total']);
        $this->assertSame(56.25,$summary['pending_total']);
        $html=view('emails.payout-report',['summary'=>$summary,'audience'=>'photographer','recipientName'=>'Tester','rangeStart'=>Carbon::parse('2026-10-04'),'rangeEnd'=>Carbon::parse('2026-10-10')])->render();
        $this->assertStringContainsString('awaiting photographer completion',$html);
    }

    public function test_late_work_is_allocated_once_with_original_date(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person,'2026-09-30');
        $invoice=$this->generate()->sole();
        $this->assertTrue($invoice->items->sole()->meta['late_work']);
        $this->assertSame('2026-09-30',$invoice->items->sole()->recorded_at->toDateString());
        $this->generate();
        $this->assertSame(1,Invoice::where('role','photographer')->count());
        $this->assertSame(1,$invoice->items()->count());
        $this->assertDatabaseCount('payout_work_allocations',1);
    }

    public function test_submitted_and_paid_lines_stay_frozen_and_new_work_is_supplemental(): void
    {
        $person=User::factory()->photographer()->create();
        $this->work($person);
        $invoice=$this->generate()->sole();
        $invoice->update(['approval_status'=>'accounts_approved','payout_edited'=>true]);
        $itemIds=$invoice->items()->pluck('id')->all();
        $invoice->items()->create(['type'=>'expense','description'=>'Approved reimbursement','quantity'=>1,'unit_amount'=>10,'total_amount'=>10]);
        $invoice->refreshTotals();
        $this->work($person,'2026-10-10','editing',56.25);
        $this->generate();
        $this->assertSame(2,Invoice::where('role','photographer')->count());
        $this->assertSame(88.75,(float)$invoice->fresh()->total_amount);
        $this->assertSame($itemIds,$invoice->items()->where('type','charge')->pluck('id')->all());
        $summary=app(PayoutReportService::class)->buildPhotographerSummaries(Carbon::parse('2026-10-04'),Carbon::parse('2026-10-10')->endOfDay())->sole();
        $this->assertSame(145.0,$summary['gross_total']);
        $this->generate();
        $this->assertSame(2,Invoice::where('role','photographer')->count());
    }

    public function test_changed_package_flags_accounts_review(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $shoot->services()->updateExistingPivot($shoot->services()->first()->id,['price'=>199,'photographer_pay'=>89.55]);
        $invoice=$this->generate()->sole();
        $this->assertSame(89.55,(float)$invoice->total_amount);
        $this->assertSame('payout_basis_changed',$invoice->unresolved_warnings[0]['code']);
        $this->assertSame(78.75,$invoice->items->sole()->meta['payout_basis']['previous_basis']['unit_pay']);
    }

    public function test_completed_floorplan_without_photo_upload_is_included(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person,'2026-10-10','scheduled',22.50);
        $shoot->services()->updateExistingPivot($shoot->services()->first()->id,['workflow_status'=>'ready','ready_at'=>'2026-10-10 22:00:00']);
        $invoice=$this->generate()->sole();
        $this->assertSame(22.50,(float)$invoice->total_amount);
        $this->assertNull($shoot->fresh()->photos_uploaded_at);
    }

    public function test_one_photographer_upload_does_not_complete_another_photographers_work(): void
    {
        $person=User::factory()->photographer()->create();
        $other=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $floor=Service::factory()->create(['name'=>'Floorplan','photographer_pay'=>22.50,'pricing_type'=>'fixed']);
        $shoot->services()->attach($floor->id,['photographer_id'=>$other->id,'quantity'=>1]);
        $rows=app(PhotographerPayoutReconciliation::class)->rows(Carbon::parse('2026-10-04'),Carbon::parse('2026-10-10'));
        $this->assertFalse($rows->where('resolved_photographer_id',$other->id)->sole()['eligible']);
        $shoot->services()->updateExistingPivot($floor->id,['ready_at'=>'2026-10-10 22:00:00']);
        $this->assertSame(22.50,(float)$this->generate()->sole()->total_amount);
    }

    public function test_multiple_raw_files_are_completion_evidence_not_multiple_earnings(): void
    {
        $person=User::factory()->photographer()->create();
        $other=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $first=$shoot->services()->first();
        $floor=Service::factory()->create(['name'=>'Floorplan','photographer_pay'=>22.50,'pricing_type'=>'fixed']);
        $shoot->services()->attach($floor->id,['photographer_id'=>$other->id,'quantity'=>1]);
        foreach(['one.jpg','two.jpg'] as $name) \App\Models\ShootFile::create([
            'shoot_id'=>$shoot->id,'shoot_service_id'=>$first->pivot->id,'filename'=>$name,'stored_filename'=>$name,
            'path'=>'shoots/'.$name,'file_type'=>'image/jpeg','file_size'=>100,'uploaded_by'=>$person->id,'media_type'=>'raw',
        ]);
        $invoice=$this->generate()->sole();
        $this->assertSame($person->id,$invoice->photographer_id);
        $this->assertSame(78.75,(float)$invoice->total_amount);
        $this->assertCount(1,$invoice->items);
        $this->assertSame(0,app(PhotographerPayoutReconciliation::class)->review($invoice)['pending_count']);
    }

    public function test_a_changed_booking_is_flagged_even_after_submission_without_rewriting_the_invoice(): void
    {
        $person=User::factory()->photographer()->create();
        $shoot=$this->work($person);
        $invoice=$this->generate()->sole();
        $invoice->update(['approval_status'=>'pending_approval','payout_edited'=>true]);
        $shoot->services()->updateExistingPivot($shoot->services()->first()->id,['price'=>199,'photographer_pay'=>89.55]);
        $review=app(PhotographerPayoutReconciliation::class)->review($invoice->fresh());
        $this->assertSame('booking_changed_after_invoice',$review['basis_warnings'][0]['code']);
        $this->assertSame(78.75,(float)$invoice->fresh()->total_amount);
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/admin/invoices/'.$invoice->id.'/approve')->assertUnprocessable()->assertJsonValidationErrors('warning_override_reason');
        $this->assertSame('pending_approval',$invoice->fresh()->approval_status);
    }
}
