<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Messaging\GroundwireSmsSender;
use App\Services\Messaging\GroundwireSmsService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GroundwireSmsController extends Controller
{
    public function __construct(private GroundwireSmsService $sms) {}

    public function provision()
    {
        return response($this->sms->provision())->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function execute(Request $r)
    {
        try {
            $device = $this->sms->authenticate($r);
            $data = validator($r->post(), [
                'to' => 'sometimes|required|string|max:100', 'body' => 'sometimes|required|string|max:1600',
                'content_type' => 'nullable|string|in:text/plain', 'client_id' => 'nullable|string|max:512',
                'last_known_sms_id' => 'nullable|integer|min:0', 'last_known_sent_sms_id' => 'nullable|integer|min:0',
                'token' => 'nullable|string|max:4096', 'app_id' => 'nullable|string|max:256', 'selector' => 'nullable|string|max:256',
            ])->validate();
            $body = match ($r->route('action')) {
                'send' => $this->send($device, $data),
                'fetch' => $this->sms->fetch($device, $data),
                'push-token' => $this->report($device, $data),
                'remove' => $this->remove($device),
            };

            return response()->json($body)->header('Cache-Control', 'no-store');
        } catch (ValidationException) {
            return response()->json(['message' => 'Check the SMS setup fields. Text messages only, maximum 1600 characters.'], 422)->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage() ?: 'SMS access unavailable.'], $e->getStatusCode())->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            // Never return/log request credentials, push tokens or provider responses.
            return response()->json(['message' => 'SMS service unavailable. Check Dashboard before retrying a send.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    private function send($device, array $data): array
    {
        abort_unless(isset($data['to'], $data['body']), 422, 'Enter the recipient and message.');

        return app(GroundwireSmsSender::class)->send($device, $data);
    }

    private function report($device, array $data): array
    {
        $this->sms->reportPush($device, $data);

        return [];
    }

    private function remove($device): array
    {
        // Keep the installation binding: removal must not let a second phone silently take over.
        LockedWrite::run(fn () => $device->update(['sms_push' => null]));

        return [];
    }
}
