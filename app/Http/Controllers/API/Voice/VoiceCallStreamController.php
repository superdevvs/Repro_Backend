<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\BusinessScheduleService;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Layer 1 + Layer 2 SSE endpoint. Streams transcript / realtime / insights /
 * memory / schedule_state / final_summary / closed events for a live call.
 *
 * Uses Laravel's StreamedResponse with no external broker. The same payloads
 * are also pushed onto the broadcast channel (driver=log by default) so a
 * Pusher/Reverb swap is config-only later.
 */
class VoiceCallStreamController extends Controller
{
    // Bounded so the connection self-terminates; the EventSource client
    // reconnects automatically, re-emitting a fresh snapshot.
    private const MAX_SECONDS = 290;
    private const HEARTBEAT_SECONDS = 10;
    private const POLL_MICROSECONDS = 750000; // 0.75s

    public function __construct(
        private readonly VoiceLiveStreamService $liveStream,
        private readonly BusinessScheduleService $schedule,
    ) {
    }

    public function __invoke(Request $request, VoiceCall $call): StreamedResponse
    {
        $callerTz = $request->query('caller_tz');
        $once = $request->boolean('once'); // test/diagnostic single-shot mode

        $response = new StreamedResponse(function () use ($call, $callerTz, $once): void {
            $this->emitSnapshot($call->fresh(), $callerTz);

            if ($once || $this->isClosed($call->fresh())) {
                $this->emitClosing($call->fresh());
                return;
            }

            $start = microtime(true);
            $lastHeartbeat = microtime(true);

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $fresh = $call->fresh();
                if (!$fresh || $this->isClosed($fresh)) {
                    $this->emitClosing($fresh ?? $call);
                    break;
                }

                if ((microtime(true) - $start) >= self::MAX_SECONDS) {
                    $this->event('closed', ['reason' => 'max_duration']);
                    $this->flush();
                    break;
                }

                if ((microtime(true) - $lastHeartbeat) >= self::HEARTBEAT_SECONDS) {
                    echo ": heartbeat\n\n";
                    $this->flush();
                    $lastHeartbeat = microtime(true);
                    $this->emitSnapshot($fresh, $callerTz);
                }

                usleep(self::POLL_MICROSECONDS);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function emitSnapshot(VoiceCall $call, ?string $callerTz): void
    {
        $snapshot = $this->liveStream->snapshot($call);

        $this->event('transcript', $snapshot['transcript']);
        $this->event('realtime', $snapshot['realtime']);
        if ($snapshot['insights'] !== null) {
            $this->event('insights', $snapshot['insights']);
        }
        $this->event('memory', $snapshot['memory']);
        $this->event('schedule_state', $this->schedule->currentState(null, $callerTz));
        $this->flush();
    }

    private function emitClosing(VoiceCall $call): void
    {
        $snapshot = $this->liveStream->snapshot($call);
        if ($snapshot['final_summary'] !== null) {
            $this->event('final_summary', $snapshot['final_summary']);
        }
        $this->event('closed', ['status' => $call->status]);
        $this->flush();
    }

    private function isClosed(?VoiceCall $call): bool
    {
        return $call === null || in_array((string) $call->status, ['completed', 'missed', 'failed', 'cancelled', 'transferred'], true);
    }

    private function event(string $event, mixed $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    private function flush(): void
    {
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
