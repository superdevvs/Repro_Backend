<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Base for CRM tests: the module switched on and the default role map in place. */
abstract class CrmTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crm.enabled' => true]);
    }

    protected function rep(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'salesRep'], $attributes));
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function superAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create($attributes);
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user);
        app()->forgetScopedInstances();

        return $user;
    }

    protected function contactWithMarketingOptIn(User $rep, array $attributes): CrmContact
    {
        $contact = $this->contactFor($rep, $attributes);
        app(\App\Services\Crm\Email\CrmMarketingConsent::class)->record($contact, true, 'written', $rep);

        return $contact;
    }

    protected function fakeAcceptedCrmMail(): void
    {
        config(['crm.email.transport' => 'resend', 'crm.email.company_address' => '1 Test Street, Washington, DC 20011']);
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        // In this environment MessagingSafetyServiceProvider already binds Resend to its in-memory double.
    }

    /** Create a contact directly, owned by $rep, bypassing the HTTP layer. */
    protected function contactFor(?User $rep, array $attributes = []): CrmContact
    {
        $stage = $attributes['stage'] ?? CrmContact::STAGE_OUTREACH;
        unset($attributes['stage']);
        $data = array_merge([
            'name' => 'Contact '.uniqid(),
            'segment' => 'residential',
            'company' => 'Test Realty',
        ], $attributes);

        return app(CrmContactService::class)->create($data, null, [
            'source' => 'system',
            'stage' => $stage,
            'assigned_rep_id' => $rep?->id,
            'skip_duplicate_check' => true,
        ]);
    }
}
