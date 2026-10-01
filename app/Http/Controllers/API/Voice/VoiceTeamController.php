<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceStaffPhone;
use App\Services\TelnyxAi\VoiceNumberSettingsResolver;
use App\Services\Voice\VoiceIncomingOfferService;
use App\Services\Voice\VoiceStaffPhoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VoiceTeamController extends Controller
{
    public function directory(Request $request, VoiceNumberSettingsResolver $numbers): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'role' => ['nullable', Rule::in(['all', 'client', 'salesRep', 'photographer', 'staff', 'contact'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $users = User::query()->selectRaw("'user:' || id AS id, id AS user_id, NULL AS contact_id, name, role, COALESCE(NULLIF(phonenumber, ''), phone) AS phone, company_name")
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', 'active'))->whereNull('locked_at');
        $contacts = Contact::query()->whereNull('user_id')->selectRaw("'contact:' || id AS id, NULL AS user_id, id AS contact_id, name, 'contact' AS role, phone, NULL AS company_name");
        $query = DB::query()->fromSub($users->unionAll($contacts), 'directory');
        if (($role = $data['role'] ?? 'all') !== 'all') {
            if ($role === 'staff') {
                $query->whereIn('role', ['admin', 'superadmin', 'editing_manager', 'editor']);
            } elseif ($role === 'salesRep') {
                $query->whereIn('role', ['salesRep', 'sales_rep', 'salesrep']);
            } else {
                $query->where('role', $role);
            }
        }
        if ($search = trim($data['q'] ?? '')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')->orWhere('company_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%');
                $digits = preg_replace('/\D/', '', $search);
                if (strlen($digits) >= 3) {
                    $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->through(function ($row) use ($numbers): array {
            $phone = $numbers->normalize((string) $row->phone);
            $valid = (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);

            return ['id' => $row->id, 'user_id' => $row->user_id ? (int) $row->user_id : null, 'contact_id' => $row->contact_id ? (int) $row->contact_id : null,
                'name' => $row->name, 'role' => $row->role, 'phone' => $valid ? $phone : null, 'callable' => $valid, 'company_name' => $row->company_name];
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function offers(Request $request, VoiceIncomingOfferService $offers): JsonResponse
    {
        return response()->json($offers->listing($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function claim(Request $request, VoiceIncomingOffer $offer, VoiceIncomingOfferService $offers): JsonResponse
    {
        $data = $request->validate(['device' => ['required', Rule::in(['browser', 'phone'])],
            'session_id' => ['required_if:device,browser', 'nullable', 'uuid'], 'idempotency_key' => ['required', 'string', 'max:128']]);
        try {
            return response()->json($offers->claim($offer, $request->user(), $data));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['message' => 'Another call operation is in progress. Refresh before retrying.', 'incoming_offer' => $offers->present($offer->fresh(), $request->user())], 409);
        } catch (\RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 409) {
                throw $e;
            }

            return response()->json(['message' => $e instanceof HttpExceptionInterface ? $e->getMessage() : 'The provider has not confirmed the connection. Retry the same answer request.',
                'incoming_offer' => $offers->present($offer->fresh(), $request->user())], $e instanceof HttpExceptionInterface ? 409 : 502);
        }
    }

    public function phoneSettings(Request $request, VoiceStaffPhoneService $phones): JsonResponse
    {
        return response()->json($phones->state($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function cancelClaim(Request $request, VoiceIncomingOffer $offer, VoiceIncomingOfferService $offers): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:128']]);

        return response()->json($offers->cancel($offer, $request->user(), $data['idempotency_key']));
    }

    public function updatePhoneSettings(Request $request, VoiceStaffPhoneService $phones): JsonResponse
    {
        return response()->json($phones->update($request->user(), $request->validate(['available' => ['sometimes', 'boolean'], 'phone_enabled' => ['sometimes', 'boolean']])));
    }

    public function requestPhoneVerification(Request $request, VoiceStaffPhoneService $phones): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return response()->json($phones->requestVerification($request->user(), $data['phone']));
    }

    public function verifyPhone(Request $request, VoiceStaffPhoneService $phones): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        return response()->json($phones->verify($request->user(), $data['code']));
    }

    public function removePhone(Request $request, VoiceStaffPhoneService $phones): JsonResponse
    {
        VoiceStaffPhone::where('user_id', $request->user()->id)->update(['phone_enabled' => false, 'phone' => null, 'verified_at' => null,
            'pending_phone' => null, 'verification_hash' => null, 'verification_expires_at' => null]);

        return response()->json($phones->state($request->user()));
    }
}
