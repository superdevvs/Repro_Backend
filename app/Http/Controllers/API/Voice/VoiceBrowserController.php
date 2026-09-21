<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Services\Voice\VoiceBrowserCallService;
use App\Services\Voice\VoiceBrowserSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VoiceBrowserController extends Controller
{
    public function __construct(private readonly VoiceBrowserSessionService $sessions, private readonly VoiceBrowserCallService $calls) {}

    public function config(Request $request): JsonResponse
    {
        return response()->json($this->sessions->configuration($request->user()));
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);

        return $this->respond(fn () => $this->sessions->connect($request->user(), $data['device_id']));
    }

    public function token(Request $request, VoiceBrowserSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        abort_unless($this->sessions->configuration($request->user())['ready'], 409, 'Browser calling is unavailable.');
        $data = $request->validate(['device_id' => ['required', 'uuid']]);

        return $this->respond(fn () => $this->sessions->issueToken($session, $data['device_id']));
    }

    public function session(Request $request, VoiceBrowserSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        return $this->respond(fn () => $this->sessions->safeState($session));
    }

    public function heartbeat(Request $request, VoiceBrowserSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate(['registered' => ['required', 'boolean']]);

        return $this->respond(fn () => $this->sessions->heartbeat($session, $data['registered']));
    }

    public function disconnect(Request $request, VoiceBrowserSession $session): JsonResponse
    {
        // Owners can always revoke their own credential, even after a role change.
        abort_unless($session->user_id === $request->user()->id, 403);

        return $this->respond(function () use ($session): array {
            $this->calls->disconnect($session);

            return ['status' => 'revoked'];
        });
    }

    public function outbound(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'uuid', 'exists:voice_browser_sessions,id'],
            'to' => ['required', 'string', 'max:32'], 'from' => ['nullable', 'string', 'max:32'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'related_shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'reason' => ['nullable', 'string', 'max:1000'], 'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        return $this->respond(fn () => $this->calls->startOutbound($request->user(), VoiceBrowserSession::findOrFail($data['session_id']), $data));
    }

    public function state(Request $request, VoiceCall $call): JsonResponse
    {
        return $this->respond(fn () => $this->calls->state($call, $request->user()));
    }

    public function takeover(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'uuid', 'exists:voice_browser_sessions,id'], 'idempotency_key' => ['required', 'string', 'max:128']]);

        return $this->respond(function () use ($request, $call, $data): array {
            $this->calls->takeover($call, $request->user(), VoiceBrowserSession::findOrFail($data['session_id']), $data);

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    public function supervise(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'uuid', 'exists:voice_browser_sessions,id'], 'idempotency_key' => ['required', 'string', 'max:128'], 'mode' => ['required', Rule::in(['monitor', 'whisper', 'barge'])]]);

        return $this->respond(function () use ($request, $call, $data): array {
            $this->calls->supervise($call, $request->user(), VoiceBrowserSession::findOrFail($data['session_id']), $data['mode'], $data['idempotency_key']);

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    public function changeSupervision(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['monitor', 'whisper', 'barge'])]]);

        return $this->respond(function () use ($request, $call, $data): array {
            $this->calls->changeSupervision($call, $request->user(), $data['mode']);

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    public function leaveSupervision(Request $request, VoiceCall $call): JsonResponse
    {
        return $this->respond(function () use ($request, $call): array {
            $this->calls->leaveSupervision($call, $request->user());

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    public function action(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['mute', 'unmute', 'hold', 'resume', 'dtmf', 'end', 'transfer', 'recording_start', 'recording_stop'])],
            'digits' => ['required_if:action,dtmf', 'string', 'regex:/^[0-9A-D*#wW]{1,32}$/'],
            'to' => ['required_if:action,transfer', 'string', 'max:32'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
        ]);

        return $this->respond(function () use ($request, $call, $data): array {
            $this->calls->action($call, $request->user(), $data);

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    public function consent(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate(['consented' => ['required', 'boolean'], 'idempotency_key' => ['sometimes', 'string', 'max:128']]);

        return $this->respond(function () use ($request, $call, $data): array {
            $this->calls->consent($call, $request->user(), $data['consented'], $data['idempotency_key'] ?? null);

            return $this->calls->state($call->fresh(), $request->user());
        });
    }

    private function authorizeSession(Request $request, VoiceBrowserSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id && ($this->sessions->canOperate($request->user()) || $this->sessions->canSupervise($request->user())), 403);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            $response = response()->json($callback());
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $response = response()->json(['message' => 'A phone operation is still in progress. Refresh its status before retrying.'], 409);
        } catch (\RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            $response = response()->json(['message' => 'The phone provider has not confirmed this operation. Refresh and retry the same request.', 'code' => 'provider_unconfirmed'], 502);
        }

        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
