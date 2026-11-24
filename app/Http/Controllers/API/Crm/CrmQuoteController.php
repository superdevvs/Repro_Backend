<?php

namespace App\Http\Controllers\API\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmQuote;
use App\Models\Service;
use App\Services\Crm\CrmQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Quotes (F3): draft, approve large discounts, send, accept or decline. */
class CrmQuoteController extends CrmController
{
    public function __construct(private readonly CrmQuoteService $quotes) {}

    /** Bookable services with their list prices, for the quote builder. */
    public function catalog(Request $request): JsonResponse
    {
        $sqft = $request->integer('sqft') ?: null;
        $items = Service::query()->bookable()->orderBy('name')->get()->map(fn (Service $s) => [
            'id' => $s->id, 'name' => $s->name, 'price' => round((float) $s->getPriceForSqft($sqft), 2), 'pricing_type' => $s->pricing_type,
            'category' => $s->category_id ? optional($s->category)->name : null,
        ]);

        return response()->json(['data' => $items->values()->all(), 'approval_percent' => (float) config('crm.quote_discount_approval_percent', 10)]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(CrmQuote::STATUSES)]]);
        $items = CrmQuote::query()->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->limit(300)->get();
        $contacts = CrmContact::query()->whereIn('id', $items->pluck('contact_id'))->get()->keyBy('id');

        return response()->json(['data' => $items->map(fn ($q) => $this->payload($q, $contacts[$q->contact_id] ?? null))->all()]);
    }

    public function forContact(Request $request, CrmContact $contact): JsonResponse
    {
        $items = CrmQuote::query()->where('contact_id', $contact->id)->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn ($q) => $this->payload($q, $contact))->all()]);
    }

    public static function rules(bool $creating): array
    {
        return [
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:40'],
            'items.*.service_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'property_address' => ['nullable', 'string', 'max:255'],
            'sqft' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function store(Request $request, CrmContact $contact): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $quote = $this->quotes->create($contact, $request->validate(self::rules(true)), $this->actor($request));

        return response()->json(['quote' => $this->payload($quote, $contact, true)], 201);
    }

    public function show(Request $request, CrmQuote $quote): JsonResponse
    {
        return response()->json(['quote' => $this->payload($quote, CrmContact::query()->find($quote->contact_id), true)]);
    }

    public function update(Request $request, CrmQuote $quote): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'crm-contacts', 'update')) {
            return $denied;
        }
        $this->quotes->update($quote, $request->validate(self::rules(false)), $this->actor($request));

        return $this->show($request, $quote->fresh());
    }

    public function action(Request $request, CrmQuote $quote, string $action): JsonResponse
    {
        $actor = $this->actor($request);
        if ($action !== 'approve' && ($denied = $this->requirePermission($request, 'crm-contacts', 'update'))) {
            return $denied;
        }
        match ($action) {
            'submit' => $this->quotes->submit($quote, $actor),
            'approve' => $this->quotes->approve($quote, $actor),
            'send' => $this->quotes->send($quote, $actor),
            'accept' => $this->quotes->accept($quote, $actor, 'rep'),
            'decline' => $this->quotes->decline($quote, $actor, $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null, 'rep'),
            default => abort(404),
        };

        return $this->show($request, $quote->fresh());
    }

    private function payload(CrmQuote $q, ?CrmContact $contact, bool $full = false): array
    {
        $out = [
            'id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'total' => $q->total, 'subtotal' => $q->subtotal,
            'discount_percent' => $q->discount_percent, 'discount_amount' => $q->discount_amount,
            'needs_approval' => $this->quotes->needsApproval($q),
            'contact' => $contact ? ['id' => $contact->id, 'name' => $contact->name, 'company' => $contact->company] : null,
            'property_address' => $q->property_address, 'valid_until' => $q->valid_until?->toDateString(),
            'sent_at' => $q->sent_at?->toIso8601String(), 'accepted_at' => $q->accepted_at?->toIso8601String(), 'declined_at' => $q->declined_at?->toIso8601String(),
            'created_by' => $this->presenter()->user($q->created_by), 'approved_by' => $this->presenter()->user($q->approved_by),
            'via_robbie' => $q->via_robbie, 'created_at' => $q->created_at?->toIso8601String(),
        ];
        if ($full) {
            $out['items'] = $q->items()->get()->map(fn ($i) => ['id' => $i->id, 'service_id' => $i->service_id, 'name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'total' => $i->total])->all();
            $out['notes'] = $q->notes;
            $out['decline_reason'] = $q->decline_reason;
            $out['public_url'] = in_array($q->status, ['sent', 'accepted', 'declined', 'expired'], true) ? $this->quotes->publicUrl($q) : null;
        }

        return $out;
    }
}
