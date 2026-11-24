<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmQuote;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmSendBlocked;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quotes (frame F3): catalogue prices from the Dashboard's services, a
 * discount above the approval threshold needs crm-quotes:approve, sending
 * moves early contacts to Estimate and books a follow-up, acceptance moves
 * the contact to Booked.
 */
class CrmQuoteService
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
    ) {}

    public function create(CrmContact $contact, array $data, User $actor, bool $viaRobbie = false): CrmQuote
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($contact, $data, $actor, $viaRobbie) {
            $quote = CrmQuote::create([
                'contact_id' => $contact->id,
                'number' => 'Q-'.Str::upper(Str::random(10)),
                'status' => 'draft',
                'property_address' => $data['property_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'discount_percent' => (float) ($data['discount_percent'] ?? 0),
                'public_token' => Str::random(48),
                'created_by' => $actor->id,
                'via_robbie' => $viaRobbie,
            ]);
            $quote->forceFill(['number' => 'Q-'.now()->format('Y').'-'.str_pad((string) $quote->id, 4, '0', STR_PAD_LEFT)])->save();
            $this->replaceItems($quote, $data['items'] ?? [], isset($data['sqft']) ? (int) $data['sqft'] : null);
            $this->activity->log($contact, 'quote_created', $actor, 'Quote '.$quote->number.' drafted', ['quote_id' => $quote->id], $viaRobbie, 'quote', $quote->id);

            return $quote->fresh('items');
        }), 'crm.quote.create');
    }

    public function update(CrmQuote $quote, array $data, User $actor): CrmQuote
    {
        if (! in_array($quote->status, ['draft', 'pending_approval', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'A sent quote can’t be changed. Create a new one.']);
        }

        return LockedWrite::run(fn () => DB::transaction(function () use ($quote, $data) {
            $quote->fill(collect($data)->only(['property_address', 'notes'])->all());
            if (array_key_exists('discount_percent', $data)) {
                $quote->discount_percent = (float) $data['discount_percent'];
            }
            // Any change to price or lines needs a fresh approval.
            $quote->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null])->save();
            if (array_key_exists('items', $data)) {
                $this->replaceItems($quote, $data['items'], isset($data['sqft']) ? (int) $data['sqft'] : null);
            } else {
                $this->recalculate($quote);
            }

            return $quote->fresh('items');
        }), 'crm.quote.update');
    }

    public function needsApproval(CrmQuote $quote): bool
    {
        return $quote->discount_percent > (float) config('crm.quote_discount_approval_percent', 10) && $quote->approved_at === null;
    }

    public function submit(CrmQuote $quote, User $actor): CrmQuote
    {
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be sent for approval.']);
        }
        $quote->forceFill(['status' => 'pending_approval'])->save();
        $contact = CrmContact::unscopedForSystem()->find($quote->contact_id);
        $this->notifyApprovers('Quote '.$quote->number.' needs approval', $actor->name.' offered '.rtrim(rtrim(number_format($quote->discount_percent, 2), '0'), '.').'% off'.($contact ? ' to '.$contact->name : '').'.', $quote);

        return $quote;
    }

    public function approve(CrmQuote $quote, User $actor): CrmQuote
    {
        if (! $this->access->can($actor, 'crm-quotes', 'approve')) {
            abort(403, 'You can’t approve quotes.');
        }
        if (! in_array($quote->status, ['draft', 'pending_approval'], true)) {
            throw ValidationException::withMessages(['status' => 'This quote is not waiting for approval.']);
        }
        $quote->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
        if ((int) $quote->created_by !== (int) $actor->id && $quote->created_by) {
            $this->notices->notify((int) $quote->created_by, 'quote_approved', 'Quote '.$quote->number.' approved', 'You can send it now.', $quote->contact_id, ['quote_id' => $quote->id]);
        }

        return $quote;
    }

    public function send(CrmQuote $quote, User $actor): CrmQuote
    {
        if (! in_array($quote->status, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'This quote can’t be sent.']);
        }
        if ($quote->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Add at least one service.']);
        }
        if ($this->needsApproval($quote)) {
            if (! $this->access->can($actor, 'crm-quotes', 'approve')) {
                $this->submit($quote, $actor);
                throw ValidationException::withMessages(['discount_percent' => 'A discount above '.config('crm.quote_discount_approval_percent', 10).'% needs approval. It has been sent to an admin.']);
            }
            $quote->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
        }
        $contact = CrmContact::query()->findOrFail($quote->contact_id);

        try {
            app(CrmEmailSender::class)->send([
                'contact' => $contact, 'sender' => $actor, 'source_type' => 'quote', 'source_id' => $quote->id,
                'content' => $this->emailContent($quote->fresh('items'), $contact), 'marketing' => false,
                'idempotency_key' => 'crm-quote-'.$quote->id, 'counts_as_contact' => true,
            ]);
        } catch (CrmSendBlocked $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return LockedWrite::run(fn () => DB::transaction(function () use ($quote, $contact, $actor) {
            $days = (int) config('crm.quote_follow_up_days', 5);
            $event = app(CrmEventService::class)->schedule($contact, [
                'type' => 'call', 'starts_at' => now()->addWeekdays($days)->setTime(10, 0)->toIso8601String(),
                'note' => 'Follow up on quote '.$quote->number, 'assigned_user_id' => $contact->assigned_rep_id ?? $actor->id,
            ], $actor);
            $quote->forceFill([
                'status' => 'sent', 'sent_at' => now(), 'valid_until' => $quote->valid_until ?? now()->addDays(30)->toDateString(),
                'follow_up_event_id' => $event->id,
            ])->save();
            if (in_array($contact->stage, [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY], true)) {
                app(CrmStageService::class)->move($contact, CrmContact::STAGE_ESTIMATE, $actor, 'system', 'Quote sent');
            }
            $this->activity->log($contact, 'quote_sent', $actor, 'Quote '.$quote->number.' sent ($'.number_format($quote->total, 2).')', ['quote_id' => $quote->id], false, 'quote', $quote->id);

            return $quote;
        }), 'crm.quote.send');
    }

    public function accept(CrmQuote $quote, ?User $actor, string $by = 'client'): CrmQuote
    {
        if ($quote->status === 'accepted') {
            return $quote;
        }
        if ($quote->status !== 'sent') {
            throw ValidationException::withMessages(['status' => 'This quote is no longer open.']);
        }
        if ($quote->valid_until && $quote->valid_until->lt(now()->startOfDay())) {
            $quote->forceFill(['status' => 'expired'])->save();
            throw ValidationException::withMessages(['status' => 'This quote has expired.']);
        }
        $contact = CrmContact::unscopedForSystem()->findOrFail($quote->contact_id);

        LockedWrite::run(fn () => DB::transaction(function () use ($quote, $contact, $actor, $by) {
            $quote->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
            if ($quote->follow_up_event_id) {
                CrmEvent::query()->whereKey($quote->follow_up_event_id)->where('status', 'planned')->update(['status' => 'cancelled', 'outcome_note' => 'Quote accepted']);
            }
            if (in_array($contact->stage, [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY, CrmContact::STAGE_ESTIMATE], true)) {
                app(CrmStageService::class)->move($contact, CrmContact::STAGE_BOOKED, $actor, 'system', 'Quote '.$quote->number.' accepted');
            }
            $this->activity->log($contact, 'quote_accepted', $actor, 'Quote '.$quote->number.' accepted'.($by === 'client' ? ' by the client' : ''), ['quote_id' => $quote->id], false, 'quote', $quote->id);
        }), 'crm.quote.accept');

        if ($contact->assigned_rep_id && (int) $contact->assigned_rep_id !== (int) $actor?->id) {
            $this->notices->notify((int) $contact->assigned_rep_id, 'quote_accepted', $contact->name.' accepted quote '.$quote->number, 'Book the shoot.', $contact->id, ['quote_id' => $quote->id]);
        }

        return $quote;
    }

    public function decline(CrmQuote $quote, ?User $actor, ?string $reason, string $by = 'client'): CrmQuote
    {
        if ($quote->status !== 'sent') {
            throw ValidationException::withMessages(['status' => 'This quote is no longer open.']);
        }
        $quote->forceFill(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason ? mb_substr($reason, 0, 255) : null])->save();
        $contact = CrmContact::unscopedForSystem()->find($quote->contact_id);
        if ($contact) {
            $this->activity->log($contact, 'quote_declined', $actor, 'Quote '.$quote->number.' declined'.($reason ? ': '.$reason : ''), ['quote_id' => $quote->id], false, 'quote', $quote->id);
            if ($contact->assigned_rep_id && $by === 'client') {
                $this->notices->notify((int) $contact->assigned_rep_id, 'quote_declined', $contact->name.' declined quote '.$quote->number, $reason, $contact->id, ['quote_id' => $quote->id]);
            }
        }

        return $quote;
    }

    public function expireDue(): int
    {
        return CrmQuote::query()->withoutGlobalScopes()->where('status', 'sent')->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->toDateString())->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public function publicUrl(CrmQuote $quote): string
    {
        return rtrim((string) config('crm.email.public_base_url'), '/').'/q/'.$quote->public_token;
    }

    /** @param  array<int, array{service_id?: int|null, name?: string|null, quantity?: int|null, unit_price?: float|null}>  $items */
    private function replaceItems(CrmQuote $quote, array $items, ?int $sqft): void
    {
        $quote->items()->delete();
        $services = Service::query()->bookable()->whereIn('id', collect($items)->pluck('service_id')->filter())->get()->keyBy('id');
        foreach (array_values($items) as $i => $item) {
            $qty = max(1, min(999, (int) ($item['quantity'] ?? 1)));
            $service = ! empty($item['service_id']) ? $services->get((int) $item['service_id']) : null;
            if (! empty($item['service_id']) && ! $service) {
                throw ValidationException::withMessages(["items.$i.service_id" => 'This service is not available.']);
            }
            $price = $service ? (float) $service->getPriceForSqft($sqft) : (float) ($item['unit_price'] ?? 0);
            if ($price < 0) {
                throw ValidationException::withMessages(["items.$i.unit_price" => 'Prices can’t be negative.']);
            }
            $quote->items()->create([
                'service_id' => $service?->id,
                'name' => mb_substr($service?->name ?? trim((string) ($item['name'] ?? 'Custom item')), 0, 190),
                'quantity' => $qty, 'unit_price' => round($price, 2), 'total' => round($price * $qty, 2), 'sort' => $i,
            ]);
        }
        $this->recalculate($quote);
    }

    private function recalculate(CrmQuote $quote): void
    {
        $subtotal = round((float) $quote->items()->sum('total'), 2);
        $percent = max(0, min(100, (float) $quote->discount_percent));
        $discount = round($subtotal * $percent / 100, 2);
        $quote->forceFill(['subtotal' => $subtotal, 'discount_percent' => $percent, 'discount_amount' => $discount, 'total' => round($subtotal - $discount, 2)])->save();
    }

    private function emailContent(CrmQuote $quote, CrmContact $contact): array
    {
        // The locked email layout allows simple text markup only, so lines are a list.
        $rows = $quote->items->map(fn ($i) => '<li>'.e($i->name).($i->quantity > 1 ? ' × '.$i->quantity : '').': $'.number_format($i->total, 2).'</li>')->implode('');
        if ($quote->discount_amount > 0) {
            $rows .= '<li>Discount ('.rtrim(rtrim(number_format($quote->discount_percent, 2), '0'), '.').'%): −$'.number_format($quote->discount_amount, 2).'</li>';
        }
        $body = '<p>Hi {{first_name}},</p><p>Here is your quote'.($quote->property_address ? ' for '.e($quote->property_address) : '').'.</p>'
            .'<ul>'.$rows.'</ul><p><strong>Total: $'.number_format($quote->total, 2).'</strong></p>'
            .($quote->notes ? '<p>'.nl2br(e($quote->notes)).'</p>' : '')
            .'<p>You can accept it online; it’s valid until '.($quote->valid_until ?? now()->addDays(30))->format('F j, Y').'.</p>';

        return [
            'subject' => 'Your quote from R/E Pro Photos ('.$quote->number.')', 'body_html' => $body, 'preheader' => 'Total $'.number_format($quote->total, 2),
            'image_url' => null, 'cta_label' => 'View and accept', 'cta_url' => $this->publicUrl($quote),
        ];
    }

    private function notifyApprovers(string $title, ?string $body, CrmQuote $quote): void
    {
        User::query()->whereIn('role', ['admin', 'superadmin'])->get()
            ->filter(fn (User $u) => $this->access->can($u, 'crm-quotes', 'approve'))
            ->each(fn (User $u) => $this->notices->notify($u->id, 'quote_approval', $title, $body, $quote->contact_id, ['quote_id' => $quote->id]));
    }
}
