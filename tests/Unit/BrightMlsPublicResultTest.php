<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Support\BrightMlsPublicResult;
use PHPUnit\Framework\TestCase;

class BrightMlsPublicResultTest extends TestCase
{
    public function test_historical_provider_errors_and_payloads_are_not_public(): void
    {
        $result = BrightMlsPublicResult::from([
            'success' => false, 'status' => 'error', 'error' => 'secret-canary', 'message' => 'secret-canary',
            'validation_errors' => ['secret-canary'], 'response' => ['headers' => ['token' => 'secret-canary']],
            'payload_snapshot' => ['client_secret' => 'secret-canary'], 'api_url' => 'https://secret-canary.test',
        ]);
        $this->assertSame(false, $result['success']);
        $this->assertSame([], $result['validation_errors']);
        $this->assertStringNotContainsString('secret-canary', json_encode($result));
        $this->assertArrayNotHasKey('response', $result);
        $this->assertContains('bright_mls_response', (new Shoot())->getHidden());
    }

    public function test_reviewed_local_validation_and_product_ids_remain_available(): void
    {
        $result = BrightMlsPublicResult::from([
            'success' => false, 'status' => 'validation_error', 'manifest_id' => 'manifest-123',
            'validation_errors' => ['Item 2: floor_plan fileName must end with .pdf.', 'Item 3: photo imageUrls.fullSize must be a valid URL.', 'secret-canary'],
        ]);
        $this->assertSame('manifest-123', $result['manifest_id']);
        $this->assertCount(2, $result['validation_errors']);
        $this->assertStringNotContainsString('secret-canary', json_encode($result));
    }
}
