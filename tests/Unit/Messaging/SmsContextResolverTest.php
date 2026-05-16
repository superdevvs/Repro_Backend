<?php

namespace Tests\Unit\Messaging;

use App\Models\Contact;
use App\Models\User;
use App\Services\Messaging\AiSms\SmsContextResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_produces_e164_from_us_10_digit(): void
    {
        $svc = new SmsContextResolverService();

        $this->assertSame('+12025550100', $svc->normalize('(202) 555-0100'));
        $this->assertSame('+12025550100', $svc->normalize('2025550100'));
        $this->assertSame('+12025550100', $svc->normalize('+1 202-555-0100'));
        $this->assertSame('', $svc->normalize(''));
    }

    public function test_resolve_returns_exact_user_match(): void
    {
        $svc = new SmsContextResolverService();
        $user = User::factory()->create([
            'role' => 'client',
            'phonenumber' => '+12025550100',
        ]);

        $resolved = $svc->resolveByE164('(202) 555-0100');

        $this->assertTrue($resolved['identified']);
        $this->assertFalse($resolved['ambiguous']);
        $this->assertSame($user->id, $resolved['user']->id);
        $this->assertSame('+12025550100', $resolved['phone_e164']);
    }

    public function test_resolve_returns_exact_contact_match(): void
    {
        $svc = new SmsContextResolverService();
        $contact = Contact::create([
            'name' => 'Taylor',
            'phone' => '+12025550150',
            'type' => 'client',
        ]);

        $resolved = $svc->resolveByE164('+12025550150');

        $this->assertTrue($resolved['identified']);
        $this->assertSame($contact->id, $resolved['contact']->id);
    }

    public function test_resolve_falls_back_to_digits_suffix_when_exactly_one_match(): void
    {
        $svc = new SmsContextResolverService();
        $user = User::factory()->create([
            'role' => 'client',
            'phonenumber' => '202-555-0177', // legacy non-E.164 stored format
        ]);

        $resolved = $svc->resolveByE164('+12025550177');

        $this->assertTrue($resolved['identified']);
        $this->assertSame($user->id, $resolved['user']->id);
    }

    public function test_resolve_treats_multi_match_as_unidentified(): void
    {
        $svc = new SmsContextResolverService();
        Contact::create(['name' => 'A', 'phone' => '202-555-0188', 'type' => 'client']);
        Contact::create(['name' => 'B', 'phone' => '(202) 555-0188', 'type' => 'client']);

        $resolved = $svc->resolveByE164('+12025550188');

        $this->assertFalse($resolved['identified']);
        $this->assertTrue($resolved['ambiguous']);
        $this->assertNull($resolved['user']);
        $this->assertNull($resolved['contact']);
    }

    public function test_resolve_returns_unidentified_when_no_match(): void
    {
        $svc = new SmsContextResolverService();

        $resolved = $svc->resolveByE164('+12025559999');

        $this->assertFalse($resolved['identified']);
        $this->assertFalse($resolved['ambiguous']);
        $this->assertNull($resolved['user']);
        $this->assertNull($resolved['contact']);
        $this->assertSame('+12025559999', $resolved['phone_e164']);
    }
}
