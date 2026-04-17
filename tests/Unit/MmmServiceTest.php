<?php

namespace Tests\Unit;

use App\Services\MmmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MmmServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validate_config_allows_a_missing_template_external_number(): void
    {
        config([
            'services.mmm.enabled' => true,
            'services.mmm.duns' => 'Wqsw5cPn3Neo9Blz',
            'services.mmm.shared_secret' => 'XxdU9n5pP8bWb4DG',
            'services.mmm.user_agent' => 'REPro Photos Tests',
            'services.mmm.punchout_url' => 'https://repro.mymarketingmatters.com/PunchoutSetup.asp',
            'services.mmm.template_external_number' => null,
            'services.mmm.deployment_mode' => 'test',
            'services.mmm.start_point' => 'category',
            'services.mmm.url_return' => 'https://app.test/api/integrations/mmm/return',
        ]);

        $this->assertNull(app(MmmService::class)->validateConfig());
    }
}
