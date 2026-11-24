<?php

namespace App\Services\Crm;

use App\Exceptions\PublicApiResponseException;
use App\Http\Requests\StoreShootRequest;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmQuote;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\Actions\CreateShootAction;
use App\Services\Shoots\ShootManagementAccess;
use App\Support\LockedWrite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrmQuoteBooking
{
    public function __construct(
        private readonly ShootManagementAccess $access,
        private readonly CreateShootAction $create,
        private readonly CrmActivityLogger $activity,
    ) {}

    public function review(CrmQuote $quote, array $details, User $actor): void
    {
        LockedWrite::run(fn () => DB::transaction(function () use ($quote, $details, $actor) {
            $quote = CrmQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ($quote->status !== 'accepted') {
                throw ValidationException::withMessages(['status' => 'Only an accepted quote can become a booking request.']);
            }
            if ($quote->booking_shoot_id) {
                if (isset($details['shoot_id']) && (int) $details['shoot_id'] !== $quote->booking_shoot_id) {
                    throw ValidationException::withMessages(['shoot_id' => 'This quote already has a different booking.']);
                }
                return;
            }
            $contact = CrmContact::query()->findOrFail($quote->contact_id);
            if (isset($details['shoot_id'])) {
                $shoot = Shoot::query()->whereKey($details['shoot_id'])->where('client_id', $contact->client_user_id)
                    ->whereNotIn('status', CrmClientShootStats::IGNORED_STATUSES)->lockForUpdate()->first();
                if (! $contact->client_user_id || ! $shoot || $shoot->isInternalTestShoot()
                    || CrmQuote::withoutGlobalScopes()->where('booking_shoot_id', $shoot->id)->exists()) {
                    throw ValidationException::withMessages(['shoot_id' => 'Choose an active booking belonging to this linked client that is not attached to another quote.']);
                }
                try {
                    $this->record($quote, $contact, $shoot, $actor, 'linked');
                } catch (UniqueConstraintViolationException $exception) {
                    if (! CrmQuote::withoutGlobalScopes()->where('booking_shoot_id', $shoot->id)->lockForUpdate()->first()) {
                        throw $exception;
                    }
                    throw ValidationException::withMessages(['shoot_id' => 'This booking has already been linked to another quote.']);
                }

                return;
            }
            $quote->fill(Arr::only($details, ['property_address', 'property_city', 'property_state', 'property_zip']))->save();
            $this->convert($quote, $contact, $actor);
        }), 'crm.quote.booking-review');
    }

    /** Runs inside the transaction that locks the accepted quote. */
    public function convert(CrmQuote $quote, CrmContact $contact, ?User $reviewer = null): void
    {
        $staff = $reviewer ?? User::find($quote->created_by);
        $items = $quote->items()->get();
        $reason = match (true) {
            ! $contact->client_user_id || ! User::query()->whereKey($contact->client_user_id)->where('role', 'client')->exists() =>
                'Link the contact to a Dashboard client, then retry the booking.',
            ! $quote->property_address || ! $quote->property_city || ! $quote->property_state || ! $quote->property_zip =>
                'Enter the property street, city, state and ZIP code to create the booking request.',
            $items->isEmpty() || $items->contains(fn ($item) => ! $item->service_id) =>
                'This quote includes custom work. Create the booking in Dashboard and link its shoot number here.',
            $items->pluck('service_id')->unique()->count() !== $items->count() =>
                'This quote repeats a service. Review the service quantities and link the booking here.',
            ! $staff || ($staff->account_status && $staff->account_status !== 'active') || ! $this->access->can($staff) =>
                'A staff member with booking permission must review this quote.',
            default => null,
        };
        if (! $reason) {
            $catalog = Service::query()->bookable()->whereKey($items->pluck('service_id'))->get()->keyBy('id');
            if ($catalog->count() !== $items->count()) {
                $reason = 'A quoted service is no longer bookable. Review the services and link the booking here.';
            } elseif ($this->access->isRestrictedSalesRep($staff) && $items->contains(
                fn ($i) => round((float) $catalog[$i->service_id]->getPriceForSqft($quote->sqft), 2) !== round((float) $i->unit_price, 2)
            )) {
                $reason = 'A service price has changed. Have an administrator review the accepted price before booking.';
            }
        }
        if (! $reason) {
            try {
                // A savepoint makes a rejected price or booking validation atomic with the created shoot.
                $shoot = DB::transaction(function () use ($quote, $contact, $items, $staff) {
                    $payload = [
                        'client_id' => $contact->client_user_id, 'rep_id' => $contact->assigned_rep_id,
                        'address' => $quote->property_address, 'city' => $quote->property_city,
                        'state' => strtoupper($quote->property_state), 'zip' => $quote->property_zip,
                        'property_details' => array_filter(['sqft' => $quote->sqft], fn ($v) => $v !== null),
                        'services' => $items->map(fn ($i) => ['id' => $i->service_id, 'quantity' => $i->quantity, 'price' => $i->unit_price])->all(),
                        'is_client_request' => true, 'shoot_notes' => 'Accepted CRM quote '.$quote->number,
                    ];
                    if ($this->access->canAdjustPricing($staff)) {
                        $payload['admin_adjusted_total_quote'] = $quote->total;
                    }
                    $request = StoreShootRequest::create('/api/shoots', 'POST', $payload);
                    $request->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $staff);
                    $request->validateResolved();
                    $result = $this->create->execute($request, $staff);
                    if (round((float) $result->shoot->total_quote, 2) !== round((float) $quote->total, 2)) {
                        throw ValidationException::withMessages(['total' => 'Booking pricing differs from the accepted quote. Review the price and link the booking here.']);
                    }
                    return $result->shoot;
                });
                $this->record($quote, $contact, $shoot, $reviewer, 'requested');
                return;
            } catch (ValidationException $error) {
                $reason = Arr::first(Arr::flatten($error->errors())) ?? 'Review the booking details and try again.';
            } catch (AuthorizationException|PublicApiResponseException $error) {
                $reason = 'A staff member must review this booking in Dashboard and link the shoot number here.';
            }
        }
        $quote->update(['booking_status' => 'needs_review', 'booking_review_reason' => mb_substr($reason, 0, 255)]);
    }

    private function record(CrmQuote $quote, CrmContact $contact, Shoot $shoot, ?User $actor, string $status): void
    {
        $quote->update(['booking_status' => $status, 'booking_shoot_id' => $shoot->id, 'booking_review_reason' => null]);
        app(CrmClientLink::class)->handleShoot($shoot, false);
        $this->activity->log($contact, 'quote_booking', $actor, 'Quote '.$quote->number.' linked to booking #'.$shoot->id,
            ['quote_id' => $quote->id, 'shoot_id' => $shoot->id], false, 'quote', $quote->id);
    }
}
