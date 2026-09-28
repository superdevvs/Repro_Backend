<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioRequest;
use App\Models\User;
use App\Services\ListingStudioAccess;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListingStudioRequestController extends Controller
{
    private const PLANS = [
        ['code' => 'plan_100', 'name' => '$100 plan', 'reference_price_usd' => 100],
        ['code' => 'plan_200', 'name' => '$200 plan', 'reference_price_usd' => 200],
        ['code' => 'plan_275', 'name' => '$275 plan', 'reference_price_usd' => 275],
        ['code' => 'custom', 'name' => 'Custom plan', 'reference_price_usd' => null],
    ];

    private const SERVICES = [
        ['code' => 'reel_generation', 'name' => 'Social media reels'],
        ['code' => 'virtual_staging', 'name' => 'Virtual staging'],
        ['code' => 'listing_description', 'name' => 'Listing descriptions'],
        ['code' => 'exclusive_listings', 'name' => 'Exclusive listings'],
        ['code' => 'digital_enhancements', 'name' => 'Digital enhancements and decluttering'],
        ['code' => 'rush_delivery', 'name' => 'Rush delivery'],
    ];

    public function __construct(private readonly ListingStudioAccess $access) {}

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => ['plans' => self::PLANS, 'services' => self::SERVICES]]);
    }

    public function clients(Request $request): JsonResponse
    {
        abort_if($this->access->role($request->user()) === 'client', 403);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');
        $query = $this->access->clients($request->user())
            ->whereNull('locked_at')
            ->whereRaw("LOWER(COALESCE(account_status, 'active')) IN ('', 'active')");
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('company_name', 'like', '%'.$search.'%');
            });
        }

        return response()->json(['data' => $query->orderBy('name')->orderBy('id')->limit(30)->get()
            ->map(fn (User $client) => $this->contact($client) + ['id' => $client->id])]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'declined', 'completed'])],
        ]);
        $query = $this->access->requests($request->user())->with(['client', 'submittedBy', 'reviewedBy']);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $page = $query->latest('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ListingStudioRequest $item) => $this->present($item)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'type' => ['required', Rule::in(['signup', 'call', 'change'])],
            'client_id' => ['nullable', 'integer'],
            'custom_client' => ['nullable', 'array:name,email,phone,company_name'],
            'custom_client.name' => ['required_with:custom_client', 'string', 'max:255'],
            'custom_client.email' => ['required_with:custom_client', 'email:rfc', 'max:255'],
            'custom_client.phone' => ['nullable', 'string', 'max:40'],
            'custom_client.company_name' => ['nullable', 'string', 'max:255'],
            'plan_code' => ['required_if:type,signup', 'nullable', Rule::in(array_column(self::PLANS, 'code'))],
            'services' => ['sometimes', 'array', 'max:6'],
            'services.*' => ['string', 'distinct', Rule::in(array_column(self::SERVICES, 'code'))],
            'details' => ['required_if:type,change', 'nullable', 'string', 'max:4000'],
            'phone' => ['required_if:type,call', 'nullable', 'string', 'max:40'],
            'preferred_time' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $client = null;
        if ($this->access->role($actor) === 'client') {
            abort_if(! empty($data['custom_client']) || (isset($data['client_id']) && (int) $data['client_id'] !== (int) $actor->id), 403);
            $client = $actor;
        } else {
            if (empty($data['client_id']) === empty($data['custom_client'])) {
                throw ValidationException::withMessages(['client_id' => 'Choose an existing client or provide a custom client contact.']);
            }
            if (! empty($data['client_id'])) {
                $client = $this->access->clients($actor)->findOrFail($data['client_id']);
                abort_unless($client->isAccountEligibleForAuthentication(), 422, 'This client account is not active.');
            }
        }

        $contact = $client ? $this->contact($client) : [
            'name' => $data['custom_client']['name'], 'email' => strtolower($data['custom_client']['email']),
            'phone' => $data['custom_client']['phone'] ?? null,
            'company_name' => $data['custom_client']['company_name'] ?? null,
        ];
        $services = $data['services'] ?? [];
        sort($services);
        $payload = [
            'client_id' => $client?->id, 'type' => $data['type'], 'contact' => $contact,
            'plan_code' => $data['plan_code'] ?? null, 'services' => $services,
            'details' => $data['details'] ?? null, 'phone' => $data['phone'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
        ];
        // A changed account profile must not turn a retry into a different submission.
        $hash = hash('sha256', json_encode(array_replace($payload, ['contact' => $client ? null : $contact]), JSON_THROW_ON_ERROR));

        [$record, $created] = LockedWrite::run(fn () => DB::transaction(function () use ($actor, $data, $payload, $hash) {
            $existing = ListingStudioRequest::query()->where('submitted_by_id', $actor->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This submission key has already been used for a different request.');

                return [$existing, false];
            }
            if ($payload['type'] === 'signup') {
                $pending = ListingStudioRequest::query()->where('type', 'signup')->where('status', 'pending');
                $payload['client_id'] !== null
                    ? $pending->where('client_id', $payload['client_id'])
                    : $pending->whereNull('client_id')->where('submitted_by_id', $actor->id)
                        ->whereRaw("LOWER(json_extract(contact, '$.email')) = ?", [strtolower($payload['contact']['email'])]);
                abort_if($pending->exists(), 409, 'A signup request for this client is already awaiting review.');
            }

            return [ListingStudioRequest::create($payload + [
                'submitted_by_id' => $actor->id, 'status' => 'pending',
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ]), true];
        }), 'listing-studio-request-create');

        return response()->json(['data' => $this->present($record->load(['client', 'submittedBy', 'reviewedBy']))], $created ? 201 : 200);
    }

    public function review(Request $request, ListingStudioRequest $listingStudioRequest): JsonResponse
    {
        abort_unless($this->access->isAdmin($request->user()), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in($listingStudioRequest->type === 'call' ? ['completed', 'declined'] : ['approved', 'declined'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        // A conditional update prevents two reviewers overwriting each other's decision.
        $updated = LockedWrite::run(fn () => ListingStudioRequest::query()
            ->whereKey($listingStudioRequest->id)->where('status', 'pending')->update([
                'status' => $data['status'], 'review_note' => $data['review_note'] ?? null,
                'reviewed_by_id' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now(),
            ]), 'listing-studio-request-review');
        abort_unless($updated === 1, 409, 'This request has already been reviewed. Refresh to see its current status.');

        return response()->json(['data' => $this->present($listingStudioRequest->fresh(['client', 'submittedBy', 'reviewedBy']))]);
    }

    private function contact(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?: $user->phonenumber,
            'company_name' => $user->company_name];
    }

    private function present(ListingStudioRequest $item): array
    {
        return [
            'id' => $item->id, 'type' => $item->type, 'status' => $item->status,
            'client_id' => $item->client_id,
            'client' => $item->client ? $this->contact($item->client) + ['id' => $item->client->id] : null,
            'contact' => $item->contact, 'plan_code' => $item->plan_code, 'services' => $item->services ?? [],
            'details' => $item->details, 'phone' => $item->phone, 'preferred_time' => $item->preferred_time,
            'review_note' => $item->review_note,
            'reviewed_by' => $item->reviewedBy ? ['id' => $item->reviewedBy->id, 'name' => $item->reviewedBy->name] : null,
            'reviewed_at' => $item->reviewed_at?->toIso8601String(), 'created_at' => $item->created_at?->toIso8601String(),
            'submitted_by' => $item->submittedBy ? ['id' => $item->submittedBy->id, 'name' => $item->submittedBy->name] : null,
        ];
    }
}
