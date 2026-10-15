<?php

namespace Tests\Feature\Auth;

use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProfileOfficePhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    public function test_client_can_save_office_phone_and_tour_visibility_then_reload_each_surface(): void
    {
        [$client, $shoot] = $this->clientAndShoot();

        $this->putJson('/api/profile', [
            'office_phone' => '  410-555-0199  ',
            'show_office_phone_on_tour' => true,
        ])->assertOk()
            ->assertJsonPath('reauth_required', false)
            ->assertJsonPath('user.office_phone', '410-555-0199')
            ->assertJsonPath('user.show_office_phone_on_tour', true);

        $this->assertSavedAccount($client, '410-555-0199', true);
        $this->assertPublicSurfaces($client, $shoot, '410-555-0199', true);
        Http::assertNothingSent();
    }

    public function test_explicit_false_survives_saving_a_nonempty_phone_and_portal_still_shows_it(): void
    {
        [$client, $shoot] = $this->clientAndShoot();

        $this->putJson('/api/profile', [
            'office_phone' => '410-555-0199',
            'show_office_phone_on_tour' => false,
        ])->assertOk()
            ->assertJsonPath('user.office_phone', '410-555-0199')
            ->assertJsonPath('user.show_office_phone_on_tour', false);

        $this->assertSavedAccount($client, '410-555-0199', false);
        $this->assertPublicSurfaces($client, $shoot, '410-555-0199', false);
        Http::assertNothingSent();
    }

    public function test_tour_visibility_can_be_toggled_without_resubmitting_the_office_phone(): void
    {
        [$client, $shoot] = $this->clientAndShoot([
            'office_phone' => '410-555-0199',
            'show_office_phone_on_tour' => true,
        ]);

        foreach ([false, true] as $visible) {
            $this->putJson('/api/profile', [
                'show_office_phone_on_tour' => $visible,
            ])->assertOk()
                ->assertJsonPath('user.office_phone', '410-555-0199')
                ->assertJsonPath('user.show_office_phone_on_tour', $visible);

            $this->assertSavedAccount($client, '410-555-0199', $visible);
            $this->assertPublicSurfaces($client, $shoot, '410-555-0199', $visible);
        }

        Http::assertNothingSent();
    }

    public function test_clearing_the_office_phone_removes_it_from_tour_and_portal_even_with_toggle_on(): void
    {
        [$client, $shoot] = $this->clientAndShoot([
            'office_phone' => '410-555-0199',
            'show_office_phone_on_tour' => true,
        ]);

        $this->putJson('/api/profile', [
            'office_phone' => '   ',
            'show_office_phone_on_tour' => true,
        ])->assertOk()
            ->assertJsonPath('user.office_phone', null)
            ->assertJsonPath('user.show_office_phone_on_tour', true);

        $this->assertSavedAccount($client, null, true);
        $this->assertPublicSurfaces($client, $shoot, null, false);
        Http::assertNothingSent();
    }

    public function test_saving_a_nonempty_phone_without_a_tour_flag_defaults_visibility_on(): void
    {
        [$client, $shoot] = $this->clientAndShoot();

        $this->putJson('/api/profile', ['office_phone' => '410-555-0199'])
            ->assertOk()
            ->assertJsonPath('user.show_office_phone_on_tour', true);

        $this->assertSavedAccount($client, '410-555-0199', true);
        $this->assertPublicSurfaces($client, $shoot, '410-555-0199', true);
        Http::assertNothingSent();
    }

    public function test_branded_tour_uses_selected_realtor_visibility_instead_of_shoot_owner_phone(): void
    {
        [$client, $shoot] = $this->clientAndShoot();
        $selectedRealtor = User::factory()->create([
            'role' => 'client',
            'office_phone' => '301-555-0123',
            'show_office_phone_on_tour' => false,
        ]);
        $shoot->forceFill(['tour_links' => ['realtor_client_id' => $selectedRealtor->id]])->save();

        $this->putJson('/api/profile', [
            'office_phone' => '410-555-0199',
            'show_office_phone_on_tour' => true,
        ])->assertOk();

        $this->assertSavedAccount($client, '410-555-0199', true);
        $this->getJson('/api/public/shoots/'.$shoot->id.'/branded')
            ->assertOk()
            ->assertJsonPath('shoot.client_name', $selectedRealtor->name)
            ->assertJsonMissingPath('shoot.office_phone');
        $this->getJson('/api/public/clients/'.$client->id.'/profile')
            ->assertOk()
            ->assertJsonPath('client.office_phone', '410-555-0199');
        Http::assertNothingSent();
    }

    /** @return array{User, Shoot} */
    private function clientAndShoot(array $attributes = []): array
    {
        $client = User::factory()->create(array_merge([
            'role' => 'client',
            'phone' => '202-555-0111',
            'phonenumber' => '202-555-0111',
            'office_phone' => null,
            'show_office_phone_on_tour' => false,
        ], $attributes));
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => 'delivered',
            'workflow_status' => 'delivered',
            'delivery_status' => 'delivered',
            'base_quote' => 100,
            'tax_amount' => 0,
            'total_quote' => 100,
            'payment_status' => 'paid',
            'tour_links' => [],
        ]);
        Payment::create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'currency' => 'USD',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        $this->withToken($client->createToken('office-phone-profile-test')->plainTextToken);

        return [$client, $shoot];
    }

    private function assertSavedAccount(User $client, ?string $phone, bool $visible): void
    {
        $fresh = $client->fresh();
        $this->assertSame($phone, $fresh->office_phone);
        $this->assertSame($visible, $fresh->show_office_phone_on_tour);
        $this->assertSame('202-555-0111', $fresh->phonenumber);
        $this->assertSame('202-555-0111', $fresh->phone);
        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'office_phone' => $phone,
            'show_office_phone_on_tour' => $visible,
        ]);
        // A separate PHP-FPM request resolves its bearer user again. Laravel's
        // in-process HTTP test kernel otherwise retains the pre-save guard user.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('office_phone', $phone)
            ->assertJsonPath('show_office_phone_on_tour', $visible);
    }

    private function assertPublicSurfaces(User $client, Shoot $shoot, ?string $phone, bool $visible): void
    {
        $branded = $this->getJson('/api/public/shoots/'.$shoot->id.'/branded')->assertOk();
        if ($visible && $phone !== null) {
            $branded->assertJsonPath('shoot.office_phone', $phone);
        } else {
            $branded->assertJsonMissingPath('shoot.office_phone');
        }
        foreach (['mls', 'g-mls'] as $type) {
            $this->getJson('/api/public/shoots/'.$shoot->id.'/'.$type)
                ->assertOk()
                ->assertJsonMissingPath('shoot.office_phone');
        }
        $portal = $this->getJson('/api/public/clients/'.$client->id.'/profile')->assertOk();
        if ($phone !== null) {
            $portal->assertJsonPath('client.office_phone', $phone);
        } else {
            $portal->assertJsonMissingPath('client.office_phone');
        }
    }
}
