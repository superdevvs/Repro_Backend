<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\TemplateVariableResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class TemplateVariableResolverTest extends TestCase
{
    public function test_resolves_client_names_and_greeting(): void
    {
        $resolver = new TemplateVariableResolver();
        $client = new User(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $variables = $resolver->resolve(['client' => $client]);

        $this->assertSame('Jane', $variables['client_first_name']);
        $this->assertSame('Doe', $variables['client_last_name']);
        $this->assertSame('Hi Jane', $variables['greeting']);
    }

    public function test_resolves_shoot_context_values(): void
    {
        $resolver = new TemplateVariableResolver();
        $shoot = new Shoot([
            'id' => 10,
            'address' => '123 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'scheduled_date' => Carbon::parse('2025-01-10'),
            'time' => '10:00 AM',
            'total_quote' => 250,
        ]);

        $variables = $resolver->resolve(['shoot' => $shoot]);

        $this->assertSame('123 Main St, Austin, TX, 78701', $variables['shoot_location']);
        $this->assertSame('Jan 10, 2025', $variables['shoot_date']);
        $this->assertSame('10:00 AM', $variables['shoot_time']);
        $this->assertSame(250, $variables['shoot_total']);
    }
}
