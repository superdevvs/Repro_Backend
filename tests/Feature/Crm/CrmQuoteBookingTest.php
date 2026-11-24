<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmQuote;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Crm\CrmQuoteService;
use Illuminate\Support\Facades\Queue;

class CrmQuoteBookingTest extends CrmTestCase
{
    private function quote(User $owner, CrmContact $contact, array $details = []): CrmQuote
    {
        $quote = app(CrmQuoteService::class)->create($contact, [
            'items' => [['service_id' => Service::factory()->create(['price' => 200, 'allow_multiple' => true])->id, 'quantity' => 2]],
            'property_address' => '101 Market Street', 'property_city' => 'Washington',
            'property_state' => 'DC', 'property_zip' => '20011', 'sqft' => 1750, ...$details,
        ], $owner);
        $quote->update(['status' => 'sent', 'sent_at' => now()]);
        return $quote;
    }

    public function test_acceptance_creates_one_real_request_at_the_accepted_total(): void
    {
        Queue::fake();
        $rep = $this->admin();
        $client = User::factory()->create(['role' => 'client']);
        $contact = $this->contactFor($rep, ['stage' => CrmContact::STAGE_ESTIMATE, 'email' => $client->email]);
        $contact->forceFill(['client_user_id' => $client->id])->save();
        $quote = $this->quote($rep, $contact, ['discount_percent' => 5]);
        $url = "/api/public/crm/quotes/{$quote->public_token}/accept";
        $this->postJson($url)->assertOk();
        $this->assertSame('requested', $quote->fresh()->booking_status, (string) $quote->fresh()->booking_review_reason);
        $this->postJson($url)->assertOk();
        $shoot = Shoot::query()->sole();
        $this->assertSame($shoot->id, $quote->fresh()->booking_shoot_id);
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);
        $this->assertSame(CrmContact::STAGE_BOOKED, $contact->fresh()->stage);
        $this->assertNull($shoot->scheduled_at);
        $this->assertEquals(380, $shoot->total_quote);
        $this->assertSame(1, $shoot->serviceItems()->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('crm_activities')->where('type', 'quote_booking')->count());
        $this->postJson("/api/public/crm/quotes/{$quote->public_token}/decline")->assertUnprocessable();
        $this->actAs($rep);
        $this->getJson("/api/crm/quotes/{$quote->id}")->assertOk()->assertJsonPath('quote.sqft', 1750);
    }

    public function test_unconfirmed_delivery_neither_publishes_the_quote_nor_advances_the_contact(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['email' => 'quote@company.example', 'stage' => CrmContact::STAGE_INQUIRY]);
        $quote = $this->quote($rep, $contact);
        $quote->update(['sent_at' => null, 'status' => 'draft']);
        $this->actAs($rep);
        $send = new \App\Models\Crm\CrmEmailSend(['status' => 'logged']);
        $mock = $this->mock(\App\Services\Crm\Email\CrmEmailSender::class);
        $mock->shouldReceive('send')->twice()->andReturn($send, new \App\Models\Crm\CrmEmailSend(['status' => 'uncertain']));
        $url = "/api/crm/quotes/{$quote->id}/send";
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('draft', $quote->fresh()->status);
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('sending', $quote->fresh()->status);
        $this->assertNull($quote->fresh()->sent_at);
        $this->assertNull($quote->fresh()->follow_up_event_id);
        $this->assertSame(CrmContact::STAGE_INQUIRY, $contact->fresh()->stage);
        $this->patchJson("/api/crm/quotes/{$quote->id}", ['discount_percent' => 9])->assertUnprocessable();
        $this->getJson("/api/public/crm/quotes/{$quote->public_token}")->assertNotFound();
    }

    public function test_staff_can_complete_missing_address_and_retry_once(): void
    {
        Queue::fake();
        $rep = $this->admin();
        $client = User::factory()->create(['role' => 'client']);
        $contact = $this->contactFor($rep, ['email' => $client->email]);
        $contact->forceFill(['client_user_id' => $client->id])->save();
        $quote = $this->quote($rep, $contact, ['property_city' => null]);
        $this->postJson("/api/public/crm/quotes/{$quote->public_token}/accept")->assertOk()
            ->assertJsonPath('quote.booking_status', 'needs_review')->assertJsonMissingPath('quote.booking_review_reason');
        $this->assertDatabaseCount('shoots', 0);
        $endpoint = "/api/crm/quotes/{$quote->id}/complete-booking";
        $this->actAs($this->rep());
        $this->postJson($endpoint, ['property_city' => 'Washington'])->assertNotFound();
        $this->actAs($rep);
        $this->postJson($endpoint, ['property_city' => 'Washington'])->assertOk();
        $this->assertSame('requested', $quote->fresh()->booking_status, (string) $quote->fresh()->booking_review_reason);
        $this->postJson($endpoint, ['property_city' => 'Washington'])->assertOk();
        $this->assertDatabaseCount('shoots', 1);
    }

    public function test_staff_can_link_an_existing_booking_for_custom_work_only_to_its_client(): void
    {
        Queue::fake();
        $rep = $this->admin();
        $client = User::factory()->create(['role' => 'client']);
        $contact = $this->contactFor($rep, ['email' => $client->email]);
        $contact->forceFill(['client_user_id' => $client->id])->save();
        $quote = $this->quote($rep, $contact, ['items' => [['name' => 'Custom work', 'unit_price' => 225]]]);
        $this->postJson("/api/public/crm/quotes/{$quote->public_token}/accept")->assertOk()->assertJsonPath('quote.booking_status', 'needs_review');
        $this->actAs($rep);
        $endpoint = "/api/crm/quotes/{$quote->id}/complete-booking";
        $wrong = Shoot::factory()->create();
        $this->postJson($endpoint, ['shoot_id' => $wrong->id])->assertUnprocessable();
        $right = Shoot::factory()->create(['client_id' => $client->id, 'status' => Shoot::STATUS_SCHEDULED]);
        $this->postJson($endpoint, ['shoot_id' => $right->id])->assertOk()->assertJsonPath('quote.booking_shoot_id', $right->id)->assertJsonPath('quote.booking_status', 'linked');
        $this->postJson($endpoint, ['shoot_id' => $wrong->id])->assertUnprocessable();
    }
}
