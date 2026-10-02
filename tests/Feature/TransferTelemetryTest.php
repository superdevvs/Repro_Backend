<?php

namespace Tests\Feature;

use App\Models\SystemOverviewRouteEvent;
use App\Models\User;
use App\Services\SystemOverviewTelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_transfer_metrics_preserve_only_bounded_allowlisted_values(): void
    {
        Event::fake();
        Sanctum::actingAs(User::factory()->create());
        $metrics = ['direction' => 'upload', 'mediaType' => 'raw', 'bytes' => 50000000,
            'transferMs' => 12500.5, 'confirmationMs' => 2000, 'totalMs' => 14500.5,
            'status' => 200, 'outcome' => 'confirmed', 'chunked' => false];
        $this->postJson('/api/system-telemetry/events', ['events' => [[
            'type' => 'transfer', 'transfer' => $metrics + ['filename' => 'private.CR3', 'url' => 'secret'],
        ]]])->assertOk()->assertJsonPath('stored', 1);
        $this->assertSame(['transfer' => $metrics], SystemOverviewRouteEvent::where('event_type', 'transfer')->firstOrFail()->payload_summary);
        $metrics['bytes'] = 'private filename';
        $this->postJson('/api/system-telemetry/events', ['events' => [[
            'type' => 'transfer', 'transfer' => $metrics,
        ]]])->assertOk()->assertJsonPath('stored', 0);
    }

    public function test_chunk_request_trace_does_not_read_the_raw_body(): void
    {
        Event::fake();
        $request = new class extends Request {
            public function getContent(bool $asResource = false): string|false|\Symfony\Component\HttpFoundation\InputStream {
                throw new \RuntimeException('Raw body must not be read by telemetry');
            }
        };
        $request->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/api/shoots/1/upload-sessions/test/chunks/0', 'CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '52428800']);
        $request->setUserResolver(fn () => User::factory()->create());
        app(SystemOverviewTelemetryService::class)->recordRequestTrace($request, response()->json(['ok' => true]));
        $this->assertDatabaseHas('system_overview_request_traces', ['request_bytes' => 52428800]);
    }
}
