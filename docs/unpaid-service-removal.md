# Unpaid service removal

Booking staff can remove customer-unpaid service lines using the normal edit and
confirmation flow, including after delivery. A fully unpaid delivered shoot may
have no remaining services. Payment eligibility is per booked line; a paid
cancellation fee does not prevent removal of an unpaid photography line. Legacy
unallocated money is not treated as proof that a line is unpaid.

The confirmed shoot write reconciles pricing, discounts/tax, payment allocation,
client invoices and unpaid-removal accounting in one database transaction. Live
receipt/payment-detail views use the resulting order and balance. Payment records,
refunds and historical payment receipts retain the money actually received.

Existing sales commission documents receive an explicit adjustment at the saved
commission rate, preserving manual differences and unrelated invoice lines. An
unpaid invoice returns to accounts review; a settled invoice remains immutable
and receives a separate adjustment document for review. No transfer or refund is
initiated. Repeated saves do not duplicate adjustments.

Earned photographer pay is preserved in `shoot_service_earning_snapshots`, using
the booked execution, recipient, completion and payout basis. This includes a
retained financial fee whose completion depends on the removed capture. Payout
generation and reconciliation retain the original work identity, preventing
duplicates even if assignments change later. Pending unperformed work does not
create an earned snapshot.

Service rows protected by editor payouts, complimentary-reshoot compensation or
provider work still use their dedicated cancellation/accounting workflows. An
unpaid customer charge is not permission to erase those records. Media files are
retained at shoot level by the existing removal flow.

This is prospective behavior: deployment does not remove any service or rewrite
historical financial records. The user confirms the selected service removal.
