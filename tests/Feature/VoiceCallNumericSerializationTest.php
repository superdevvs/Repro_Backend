<?php

namespace Tests\Feature;

use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VoiceCallNumericSerializationTest extends TestCase
{
    use RefreshDatabase;

    public static function durations(): array
    {
        return ['unset' => [null], 'unanswered' => [0], 'whole seconds' => [30], 'fractional legacy duration' => [30.125]];
    }

    #[DataProvider('durations')]
    public function test_reloaded_call_serializes_duration_as_a_number_without_losing_fractions(int|float|null $duration): void
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'completed',
            'from_phone' => '+12025550100', 'to_phone' => '+12025550101', 'duration_seconds' => $duration]);
        $this->assertSame($duration, $call->fresh()->duration_seconds);
        $this->assertSame($duration, json_decode($call->fresh()->toJson(), true, 512, JSON_THROW_ON_ERROR)['duration_seconds']);
    }
}
