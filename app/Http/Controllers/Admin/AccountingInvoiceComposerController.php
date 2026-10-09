<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccountingInvoiceComposerController extends Controller
{
    public function options(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'client_id' => 'nullable|integer|exists:users,id']);
        $search = $data['q'] ?? '';
        if (! isset($data['client_id'])) {
            return response()->json(['data' => User::where('role', 'client')->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))->orderBy('name')->limit(30)->get(['id', 'name', 'email'])]);
        }
        $shoots = Shoot::with('services')->where('client_id', $data['client_id'])->excludeHistoricalImportsFromNewBilling()->whereNotIn('status', ['cancelled', 'canceled', 'on_hold', 'hold', 'import_draft'])->where('address', 'like', '%'.$search.'%')->latest('scheduled_date')->limit(30)->get();

        return response()->json(['data' => $shoots->map(function ($shoot) {
            $existing = Invoice::where('role', 'client')->whereNotIn('status', ['void', 'cancelled', 'canceled'])->where(fn ($q) => $q->where('shoot_id', $shoot->id)->orWhereHas('shoots', fn ($q) => $q->where('shoots.id', $shoot->id))->orWhereHas('items', fn ($q) => $q->where('shoot_id', $shoot->id)))->value('invoice_number');

            return ['id' => $shoot->id, 'address' => implode(', ', array_filter([$shoot->address, $shoot->city, $shoot->state, $shoot->zip])), 'date' => $shoot->scheduled_date?->toDateString(), 'existing_invoice' => $existing, 'lines' => $shoot->services->map(fn ($service) => ['description' => $service->name, 'quantity' => max(1, (int) ($service->pivot->quantity ?? 1)), 'price' => round((float) ($service->pivot->price ?? $service->price ?? 0), 2)])];
        })]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shoot_id' => 'nullable|integer|exists:shoots,id', 'operation_key' => 'required|uuid', 'client_id' => 'required|integer|exists:users,id',
            'address' => 'required|string|max:2000', 'issue_date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:issue_date', 'notes' => 'nullable|string|max:5000',
            'discount' => 'required|numeric|min:0|decimal:0,2', 'tax_rate' => 'required|numeric|between:0,100|decimal:0,2',
            'lines' => 'required|array|min:1|max:50', 'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|integer|between:1,10000', 'lines.*.price' => 'required|numeric|between:0,1000000|decimal:0,2',
        ]);
        $client = User::findOrFail($data['client_id']);
        abort_unless(strtolower($client->role) === 'client', 422, 'Select a client account.');
        $number = 'M-'.strtoupper(substr(hash('sha256', $data['operation_key']), 0, 16));
        $fingerprint = hash('sha256', json_encode($data));
        $subtotal = collect($data['lines'])->sum(fn ($l) => (int) round($l['price'] * 100) * $l['quantity']);
        $discount = (int) round($data['discount'] * 100);
        abort_if($discount > $subtotal, 422, 'Discount exceeds service total.');
        $tax = (int) round(($subtotal - $discount) * $data['tax_rate'] / 100);
        abort_if($subtotal - $discount + $tax <= 0, 422, 'Invoice total must be positive.');
        $invoice = LockedWrite::run(fn () => DB::transaction(function () use ($data, $number, $fingerprint, $subtotal, $discount, $tax, $request) {
            if ($existing = Invoice::where('invoice_number', $number)->first()) {
                abort_unless((int) $existing->client_id === (int) $data['client_id'] && data_get($existing->items()->first()?->meta, 'request_hash') === $fingerprint, 409, 'Operation key already used.');

                return $existing;
            }
            if (! empty($data['shoot_id'])) {
                $shoot = Shoot::where('client_id', $data['client_id'])->excludeHistoricalImportsFromNewBilling()->findOrFail($data['shoot_id']);
                app(\App\Services\Invoices\InvoiceAdjustmentService::class)->assertClientPaymentAllowedForShoot($shoot);
                abort_if(Invoice::where('role', 'client')->whereNotIn('status', ['void', 'cancelled', 'canceled'])->where(fn ($q) => $q->where('shoot_id', $shoot->id)->orWhereHas('shoots', fn ($q) => $q->where('shoots.id', $shoot->id))->orWhereHas('items', fn ($q) => $q->where('shoot_id', $shoot->id)))->exists(), 409, 'This shoot already has an invoice. Open the existing invoice instead.');
            }
            $invoice = Invoice::create(['period_start' => $data['issue_date'], 'period_end' => $data['issue_date'], 'shoot_id' => $data['shoot_id'] ?? null, 'invoice_number' => $number, 'client_id' => $data['client_id'], 'user_id' => $data['client_id'], 'role' => 'client', 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'], 'status' => 'draft', 'is_sent' => false, 'is_paid' => false, 'amount_paid' => 0, 'subtotal' => ($subtotal - $discount) / 100, 'tax' => $tax / 100, 'total' => ($subtotal - $discount + $tax) / 100, 'total_amount' => ($subtotal - $discount + $tax) / 100]);
            foreach ($data['lines'] as $line) {
                $invoice->items()->create(['shoot_id' => $data['shoot_id'] ?? null, 'type' => 'charge', 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_amount' => $line['price'], 'total_amount' => round($line['price'] * $line['quantity'], 2), 'meta' => ['document_notes' => $data['notes'] ?? null, 'request_hash' => $fingerprint, 'source' => 'manual_invoice', 'property_address' => $data['address'], 'service_name' => $line['description']]]);
            }
            if ($discount) {
                $invoice->items()->create(['type' => 'charge', 'description' => 'Invoice discount', 'quantity' => 1, 'unit_amount' => -$discount / 100, 'total_amount' => -$discount / 100, 'meta' => ['source' => 'manual_discount']]);
            }
            $invoice->recordAuditEvent('created', $request->user(), 'Manual client invoice saved as draft. No payment or email created.');

            return $invoice;
        }));

        return response()->json(['data' => $invoice->fresh(['client', 'items', 'shoot'])], 201);
    }

    public function recordPayment(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['operation_key' => 'required|uuid', 'amount_paid' => 'required|numeric|gt:0', 'paid_at' => 'required|date', 'payment_method' => 'required|string', 'payment_details' => 'nullable|array']);
        $key = 'accounting-payment:'.$invoice->id.':'.$data['operation_key'];
        $fingerprint = hash('sha256', json_encode($data));

        return Cache::lock('accounting-payment:'.$invoice->id.':lock', 60)->block(3, function () use ($key, $fingerprint, $invoice, $request) {
            if ($cached = Cache::get($key)) {
                abort_unless($cached['hash'] === $fingerprint, 409, 'Operation key already used with different payment details.');

                return response()->json($cached['body'], $cached['status']);
            }
            Cache::put($key, ['hash' => $fingerprint, 'body' => ['message' => 'Payment outcome is unconfirmed. Check payment history before recording again.'], 'status' => 409], now()->addDays(7));
            $result = app(\App\Http\Controllers\Admin\InvoiceController::class)->markPaid($request, $invoice->fresh());
            if ($result->getStatusCode() < 500) {
                Cache::put($key, ['hash' => $fingerprint, 'body' => $result->getData(true), 'status' => $result->getStatusCode()], now()->addDays(7));
            }

            return $result;
        });
    }

    public function update(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['issue_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:issue_date', 'notes' => 'nullable|string|max:5000']);
        $invoice = LockedWrite::run(fn () => DB::transaction(function () use ($request, $invoice, $data) {
            $invoice = Invoice::findOrFail($invoice->id);
            abort_unless($invoice->role === 'client' && $invoice->status === 'draft' && (float) $invoice->amount_paid === 0.0 && ! $invoice->hasRelatedPaymentRecords(), 409, 'Only unpaid drafts may be edited. Use an adjustment for an issued invoice.');
            $notes = $data['notes'] ?? null;
            unset($data['notes']);
            $invoice->fill($data)->save();
            if ($item = $invoice->items()->where('meta->source', 'manual_invoice')->first()) {
                $item->update(['meta' => array_merge($item->meta ?? [], ['document_notes' => $notes])]);
            }$invoice->recordAuditEvent('edited', $request->user(), 'Draft dates and notes updated. Pricing unchanged.');

            return $invoice;
        }));

        return response()->json(['data' => $invoice->fresh(['client', 'items', 'shoot'])]);
    }

    public function send(Request $request, Invoice $invoice, MessagingService $messaging)
    {
        $input = $request->validate(['operation_key' => 'required|uuid', 'subject' => 'required|string|max:200', 'message' => 'required|string|max:5000']);
        abort_unless($invoice->role === 'client' && ! in_array($invoice->status, ['void', 'cancelled', 'canceled', 'refunded'], true), 422, 'Only valid client invoices can be sent here.');
        $invoice->loadMissing(['client', 'items']);
        abort_unless(filter_var($invoice->client?->email, FILTER_VALIDATE_EMAIL), 422, 'Client has no valid email address.');
        abort_if($invoice->suppressesExternalNotifications(), 422, 'External notifications are disabled for this invoice.');
        $key = 'manual-invoice-send:'.$invoice->id.':'.$input['operation_key'];
        $result = Cache::lock($key.':lock', 60)->block(3, function () use ($key, $invoice, $input, $request, $messaging) {
            if ($existing = Cache::get($key)) {
                return $existing;
            }
            // Persist the attempt before calling the provider. An uncertain delivery must not be resent automatically.
            Cache::put($key, ['accepted' => false, 'message' => 'Delivery attempt is pending. Check message history before retrying.'], now()->addDays(7));
            try {
                $rows = $invoice->items->filter(fn ($i) => $i->type !== 'payment')->map(fn ($i) => '<tr><td>'.e($i->description).'</td><td>'.e($i->quantity).'</td><td>$'.number_format((float) $i->total_amount, 2).'</td></tr>')->implode('');
                $html = '<h2>Invoice '.e($invoice->invoice_number).'</h2><p>'.nl2br(e($input['message'])).'</p><table>'.$rows.'</table><p>Total: $'.number_format((float) $invoice->total, 2).'</p><p>Tax included: $'.number_format((float) $invoice->tax, 2).'</p><p>Due: '.e($invoice->due_date?->toDateString()).'</p><p><a href="'.e(config('app.url').'/accounting').'">View your invoices in Repro</a></p>';
                $message = $messaging->sendEmail(['to' => $invoice->client->email, 'subject' => $input['subject'], 'body_html' => $html, 'body_text' => strip_tags(str_replace('</p>', "\n", $html)), 'send_source' => 'MANUAL', 'user_id' => $request->user()->id, 'related_invoice_id' => $invoice->id, 'related_account_id' => $invoice->client_id]);
                $accepted = in_array(strtoupper($message->status), ['SENT', 'DELIVERED'], true);
                if ($accepted) {
                    if ($invoice->status === 'draft') {
                        $invoice->status = 'sent';
                    }
                    $invoice->is_sent = true;
                    $invoice->save();
                }
                $invoice->recordAuditEvent($accepted ? 'sent' : 'send_failed', $request->user(), $accepted ? 'Invoice accepted by email provider.' : 'Invoice email was not accepted.', ['message_id' => $message->id, 'delivery_status' => $message->status, 'operation_key' => $input['operation_key']]);
                $result = ['accepted' => $accepted, 'message_id' => $message->id, 'message' => $accepted ? 'Invoice accepted by email provider.' : 'Email was not sent. Check message history and email permissions.'];
            } catch (\Throwable $e) {
                report($e);
                $result = ['accepted' => false, 'message' => 'Delivery could not be confirmed. Check message history before retrying.'];
            }
            Cache::put($key, $result, now()->addDays(7));

            return $result;
        });

        return response()->json($result, $result['accepted'] ? 200 : 409);
    }
}
