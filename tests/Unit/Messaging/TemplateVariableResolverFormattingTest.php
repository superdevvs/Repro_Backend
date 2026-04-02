<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\TemplateVariableResolver;
use Tests\TestCase;

class TemplateVariableResolverFormattingTest extends TestCase
{
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
