<?php
namespace Tests\Feature;

use App\Models\{Invoice,Service,Shoot,User};
use App\Services\{InvoiceService,PayoutReportService,EditorPayoutService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Mail,Notification,Queue};
use Tests\TestCase;

class HistoricalImportBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
    }

    private function historicalShoot(User $client): Shoot
    {
        return Shoot::factory()->create([
            'client_id'=>$client->id,'status'=>'delivered','workflow_status'=>'delivered',
            'scheduled_date'=>'2026-09-15','completed_at'=>'2026-09-16',
            'external_booking_payload'=>['source_record'=>['COMPANY NOTES'=>'internal only'],
                'legacy_migration'=>['historical_payments_only'=>true,'notifications_suppressed'=>true]],
        ]);
    }

    public function test_period_billing_does_not_rebill_imported_balances(): void
    {
        $client=User::factory()->create(['role'=>'client']);$historical=$this->historicalShoot($client);
        $normal=Shoot::factory()->create(['client_id'=>$client->id,'scheduled_date'=>'2026-09-15','base_quote'=>100,'tax_amount'=>6,'total_quote'=>106]);
        $invoice=app(InvoiceService::class)->generateInvoice($client,'client',Carbon::parse('2026-09-01'),Carbon::parse('2026-09-30'));
        $this->assertSame(106.0,(float)$invoice->total);
        $this->assertFalse($invoice->items()->where('shoot_id',$historical->id)->exists());
        $this->assertTrue($invoice->items()->where('shoot_id',$normal->id)->exists());
    }

    public function test_only_historical_period_reuses_existing_document_without_an_empty_new_bill(): void
    {
        $client=User::factory()->create(['role'=>'client']);$historical=$this->historicalShoot($client);
        $existing=Invoice::factory()->create(['user_id'=>$client->id,'client_id'=>$client->id,'shoot_id'=>$historical->id,'role'=>'client','status'=>'paid']);
        $count=Invoice::count();
        $invoice=app(InvoiceService::class)->generateInvoice($client,'client',Carbon::parse('2026-09-01'),Carbon::parse('2026-09-30'));
        $this->assertSame($existing->id,$invoice->id);$this->assertSame($count,Invoice::count());
    }

    public function test_historical_work_never_creates_new_photographer_rep_or_editor_payouts(): void
    {
        $client=User::factory()->create(['role'=>'client']);$shoot=$this->historicalShoot($client);
        $photographer=User::factory()->create(['role'=>'photographer']);
        $rep=User::factory()->create(['role'=>'salesRep']);$editor=User::factory()->create(['role'=>'editor','metadata'=>['photo_edit_rate'=>25]]);
        $shoot->update(['photographer_id'=>$photographer->id,'rep_id'=>$rep->id,'sales_rep_pay_enabled'=>true]);
        $service=Service::factory()->create(['name'=>'25 HDR Photos','photographer_pay'=>75,'photo_count'=>25]);
        $shoot->services()->attach($service->id,['price'=>200,'photographer_pay'=>75,'photographer_id'=>$photographer->id,'editor_id'=>$editor->id,'editing_completed_at'=>'2026-09-16']);
        $start=Carbon::parse('2026-09-01');$end=Carbon::parse('2026-09-30')->endOfDay();
        $this->assertCount(0,app(PayoutReportService::class)->buildPhotographerSummaries($start,$end));
        $this->assertCount(0,app(PayoutReportService::class)->buildSalesRepSummaries($start,$end));
        $this->assertCount(0,app(InvoiceService::class)->generateForPeriod($start,$end));
        $this->assertCount(0,app(InvoiceService::class)->generateSalesRepInvoicesForPeriod($start,$end));
        app(EditorPayoutService::class)->syncPayouts($start,$end);
        $this->assertDatabaseMissing('editor_payouts',['shoot_id'=>$shoot->id]);
        Mail::assertNothingSent();Notification::assertNothingSent();Queue::assertNothingPushed();
    }

    public function test_raw_serialization_hides_source_notes_but_retains_notification_suppression(): void
    {
        $shoot=$this->historicalShoot(User::factory()->create(['role'=>'client']));
        $this->assertArrayNotHasKey('external_booking_payload',$shoot->toArray());
        $this->assertTrue($shoot->suppressesExternalNotifications());
    }

    public function test_delivered_historical_shoots_do_not_create_missing_raw_upload_alerts(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        try {
            $photographer=User::factory()->create(['role'=>'photographer']);
            $shoot=$this->historicalShoot(User::factory()->create(['role'=>'client']));
            $shoot->update(['photographer_id'=>$photographer->id,'photos_uploaded_at'=>null]);
            foreach ([User::factory()->admin()->create(),$photographer] as $user) {
                $response=$this->actingAs($user)->getJson('/api/robbie/insights')->assertOk();
                $ids=array_column($response->json('insights'),'id');
                $this->assertNotContains('admin-late-raw',$ids);
                $this->assertNotContains('photographer-upload',$ids);
            }
        } finally {Carbon::setTestNow();}
    }

    public function test_historical_tours_preserve_published_audience_links_without_private_import_metadata(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['media.tiered_storage_enabled'=>false,'media.local_disk'=>'local']);
        $shoot=$this->historicalShoot(User::factory()->create(['role'=>'client']));
        $payload=$shoot->external_booking_payload;$payload['legacy_migration']['released_to_history']=true;
        $shoot->update(['external_booking_payload'=>$payload,'tour_links'=>[
            'zillow_3d'=>'https://www.zillow.com/view-3d-home/test',
            'video_branded'=>'https://media.example.test/Branded.mp4',
            'video_mls'=>'https://media.example.test/Unbranded.mp4',
        ]]);
        $service=app(\App\Services\Shoots\ShootPublicAssetsService::class);
        $branded=$service->buildTypedPublicAssets($shoot->fresh(),'branded',false);
        $mls=$service->buildTypedPublicAssets($shoot->fresh(),'mls',false);
        // The current public contract exposes explicitly published audience links,
        // not private import flags or legacy uploaded-file label heuristics.
        $this->assertSame('https://media.example.test/Branded.mp4',$branded['video_link']);
        $this->assertSame('https://media.example.test/Unbranded.mp4',$mls['video_link']);
        $this->assertArrayNotHasKey('video_branded',$mls['tour_links']);
        foreach ([$branded,$mls] as $public) {
            $this->assertArrayNotHasKey('historical_import',$public);
            $this->assertArrayNotHasKey('external_booking_payload',$public['shoot']);
            $this->assertStringNotContainsString('internal only',json_encode($public));
        }
        $this->assertArrayNotHasKey('zillow_3d',$mls['tour_links']);
        $this->assertSame('https://www.zillow.com/view-3d-home/test',$branded['tour_links']['zillow_3d']);
    }
}
