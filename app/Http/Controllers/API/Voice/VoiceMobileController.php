<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceMobileSession;
use App\Services\Voice\VoiceMobileCallService;
use App\Services\Voice\VoiceMobileCallerIdService;
use App\Services\Voice\VoiceMobileSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VoiceMobileController extends Controller
{
    public function __construct(private VoiceMobileSessionService $sessions, private VoiceMobileCallService $calls) {}

    private function owner(Request $r, string $id, bool $cleanup = false): VoiceMobileSession
    {
        abort_if($r->attributes->get('is_impersonating') || $r->hasHeader('X-Impersonate-User-Id'), 403, 'Switch back to your own account for calling.');

        $session = VoiceMobileSession::where('id', strtolower($id))->where('user_id', $r->user()->id)->firstOrFail();
        // Same-owner reauthentication may clean up an old registration, but it
        // cannot operate or renew the old login's calling credential.
        abort_unless($this->sessions->currentToken($r->user()), 401);
        abort_unless($cleanup || $this->sessions->matchesCurrentToken($session, $r->user()), 403);
        return $session;
    }

    private function respond(callable $run): JsonResponse
    {
        try {
            $result = $run();

            return response()->json($result)->header('Cache-Control', 'no-store, private');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'This call is unavailable.'], 404)->header('Cache-Control', 'no-store, private');
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage() ?: 'This call is unavailable.'], $e->getStatusCode())->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Calling could not confirm this operation. Retry to check its result.'], 503)->header('Cache-Control', 'no-store, private');
        }
    }

    public function bootstrap(Request $r): JsonResponse
    {
        abort_if($r->attributes->get('is_impersonating') || $r->hasHeader('X-Impersonate-User-Id'), 403);
        $d = $r->validate(['device_id' => ['required', 'uuid'], 'platform' => ['required', 'in:ios'], 'apns_environment' => ['required', 'in:debug,production'], 'push_token' => ['nullable', 'string', 'regex:/^(?:[A-Fa-f0-9]{64,512})?$/']]);

        return $this->respond(fn () => $this->sessions->bootstrap($r->user(), $d));
    }

    public function callerIds(Request $r, string $session): JsonResponse
    {
        $s = $this->owner($r, $session);

        return $this->respond(function () use ($s) {
            abort_unless($this->sessions->authorized($s), 403);

            return app(VoiceMobileCallerIdService::class)->options($s->user);
        });
    }

    public function outbound(Request $r, string $session): JsonResponse
    {
        $s = $this->owner($r, $session);
        $d = $r->validate(['to' => ['required', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'from' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'idempotency_key' => ['required', 'uuid']]);

        return $this->respond(fn () => $this->calls->outbound($s, $d['to'], $d['idempotency_key'], $d['from'] ?? null));
    }

    public function claim(Request $r, string $session, string $sdk): JsonResponse
    {
        $s = $this->owner($r, $session);
        $d = $r->validate(['idempotency_key' => ['required', 'uuid'], 'offer_token' => ['required', 'string', 'max:256']]);
        abort_unless(strtolower($d['idempotency_key']) === strtolower($sdk), 422);

        return $this->respond(fn () => $this->calls->claim($s, $sdk, $d['offer_token']));
    }

    public function state(Request $r, string $session, string $sdk): JsonResponse
    {
        $s = $this->owner($r, $session);

        return $this->respond(fn () => $this->calls->state($s, $sdk));
    }

    public function end(Request $r, string $session, string $sdk): JsonResponse
    {
        $s = $this->owner($r, $session, true);
        $d = $r->validate(['idempotency_key' => ['required', 'uuid'], 'offer_token' => ['nullable', 'string', 'max:256']]);
        abort_unless(strtolower($d['idempotency_key']) === strtolower($sdk), 422);

        return $this->respond(function () use ($s, $sdk, $d) {
            $this->calls->end($s, $sdk, $d['offer_token'] ?? null);

            return ['status' => 'ended'];
        });
    }

    public function revoke(Request $r, string $session): JsonResponse
    {
        $s = $this->owner($r, $session, true);

        return $this->respond(function () use ($s) {
            $this->sessions->revoke($s);

            return ['status' => 'revoked'];
        });
    }
}
