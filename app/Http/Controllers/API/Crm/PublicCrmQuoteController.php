<?php

namespace App\Http\Controllers\API\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmQuote;
use App\Services\Crm\CrmQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The page a client opens from the quote email: view, accept or decline. */
class PublicCrmQuoteController extends Controller
{
    public function __construct(private readonly CrmQuoteService $quotes) {}

    public function show(string $token): JsonResponse
    {
        $quote = $this->find($token);
        if ($quote->status === 'sent' && ! $quote->viewed_at) {
            $quote->forceFill(['viewed_at' => now()])->save();
        }
        $contact = CrmContact::unscopedForSystem()->find($quote->contact_id);

        return response()->json(['quote' => [
            'number' => $quote->number, 'status' => $quote->status, 'for' => $contact?->name, 'company' => $contact?->company,
            'property_address' => $quote->property_address, 'valid_until' => $quote->valid_until?->toDateString(),
            'items' => $quote->items()->get()->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'total' => $i->total])->all(),
            'subtotal' => $quote->subtotal, 'discount_percent' => $quote->discount_percent, 'discount_amount' => $quote->discount_amount, 'total' => $quote->total,
            'notes' => $quote->notes,
            'can_respond' => $quote->status === 'sent' && (! $quote->valid_until || ! $quote->valid_until->lt(now()->startOfDay())),
        ]]);
    }

    public function accept(string $token): JsonResponse
    {
        $this->quotes->accept($this->find($token), null, 'client');

        return $this->show($token);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->quotes->decline($this->find($token), null, $data['reason'] ?? null, 'client');

        return $this->show($token);
    }

    private function find(string $token): CrmQuote
    {
        abort_unless(strlen($token) === 48, 404);

        return CrmQuote::query()->withoutGlobalScopes()->where('public_token', $token)->whereNotIn('status', ['draft', 'pending_approval', 'approved'])->firstOrFail();
    }
}
