<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceSipDevice;
use App\Services\Voice\VoiceSipDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VoiceSipDeviceController extends Controller
{
    public function __construct(private VoiceSipDeviceService $devices) {}

    public function index(Request $r): JsonResponse
    {
        return $this->result(fn () => $this->devices->state($r->user()));
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate(['label' => ['required', 'string', 'max:80'], 'caller_id' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'], 'creation_key' => ['required', 'uuid']]);

        return $this->result(fn () => $this->devices->create($r->user(), $data));
    }

    public function credentials(Request $r, VoiceSipDevice $device): JsonResponse
    {
        return $this->result(fn () => $this->devices->credentials($r->user(), $device));
    }

    public function update(Request $r, VoiceSipDevice $device): JsonResponse
    {
        $data = $r->validate(['enabled' => ['sometimes', 'boolean'], 'caller_id' => ['sometimes', 'regex:/^\+[1-9]\d{7,14}$/']]);

        return $this->result(fn () => $this->devices->update($r->user(), $device, $data));
    }

    public function destroy(Request $r, VoiceSipDevice $device): JsonResponse
    {
        return $this->result(function () use ($r, $device) {
            $this->devices->revoke($r->user(), $device);

            return ['status' => 'revoked'];
        });
    }

    private function result(callable $fn): JsonResponse
    {
        try {
            $body = $fn();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['message' => 'The phone provider has not confirmed this operation. Retry to reconcile the same request.'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json($body)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }
}
