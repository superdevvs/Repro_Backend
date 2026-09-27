<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\TemplateVariableResolver;
use Tests\TestCase;

class TemplateVariableResolverFormattingTest extends TestCase
{
    public function test_photographer_service_details_hide_client_prices_in_both_channels(): void
    {
        $service = new \App\Models\Service(['name' => 'HDR Photos', 'price' => 200]);
        $service->setRelation('pivot', new \Illuminate\Database\Eloquent\Relations\Pivot(['price' => 200, 'quantity' => 2]));
        $shoot = new \App\Models\Shoot(['address' => '1 Fixture Way']);
        $shoot->setRelation('services', collect([$service]));
        $shoot->setRelation('photographer', null);
        $resolver = new TemplateVariableResolver();
        foreach (['photographer', 'previous_photographer', 'new_photographer'] as $role) {
            $variables = $resolver->resolve(['shoot' => $shoot, 'recipient_type' => $role]);
            foreach (['services_provided', 'services_provided_html'] as $field) {
                $this->assertStringContainsString('HDR Photos', $variables[$field]);
                $this->assertStringContainsString('x2', $variables[$field]);
                $this->assertStringNotContainsString('$200.00', $variables[$field]);
            }
        }
        $client = $resolver->resolve(['shoot' => $shoot, 'recipient_type' => 'client']);
        $this->assertStringContainsString('$200.00', $client['services_provided']);
        $this->assertStringContainsString('$200.00', $client['services_provided_html']);
    }

    public function test_receipts_use_the_dashboard_when_provider_url_is_blank_and_keep_available_hosted_receipts(): void
    {
        $payment = new \App\Models\Payment(['amount' => 50, 'payment_method' => 'stripe', 'stripe_payment_id' => 'pi_receipt',
            'payment_details' => ['receipt_url' => '']]);
        $payment->id = 31;
        $resolver = new TemplateVariableResolver();
        $context = ['payment' => $payment, 'dashboard_link' => 'https://reprodashboard.com/shoots/1'];
        $variables = $resolver->resolve($context);
        $this->assertSame($context['dashboard_link'], $variables['receipt_link']);
        $this->assertSame('Payment #31: $50.00', $variables['payment_items']);
        $payment->payment_details = ['receipt_url' => '', 'hosted_receipt_url' => 'https://pay.stripe.com/receipts/example'];
        $this->assertSame('https://pay.stripe.com/receipts/example', $resolver->resolve($context)['receipt_link']);
    }

    public function test_structures_shoot_change_html_with_before_after_sections(): void
    {
        $resolver = new TemplateVariableResolver();

        $variables = $resolver->resolve([
            'shoot_changes' => "Services: HDR Photos (\$175.00), Floor Plans (\$125.00) -> HDR Photos (\$175.00)\nBase Quote: \$300.00 -> \$175.00",
            'shoot_changes_html' => 'Services: HDR Photos ($175.00), Floor Plans ($125.00) -&gt; HDR Photos ($175.00)<br>Base Quote: $300.00 -&gt; $175.00',
        ]);

        $this->assertStringContainsString('Before', $variables['shoot_changes_html']);
        $this->assertStringContainsString('After', $variables['shoot_changes_html']);
        $this->assertStringContainsString('text-decoration:line-through', $variables['shoot_changes_html']);
        $this->assertStringContainsString('Base Quote', $variables['shoot_changes_html']);
    }
}
