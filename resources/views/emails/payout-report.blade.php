@component('mail::message')
# {{ $recipientName }},

Here is your payout recap for **{{ $rangeStart->format('M d') }} – {{ $rangeEnd->format('M d, Y') }}**.

@component('mail::panel')
Role: **{{ ucfirst($audience) }}**  
Completed shoots: **{{ $summary['shoot_count'] ?? 0 }}**  
Gross total: **${{ number_format($summary['gross_total'] ?? 0, 2) }}**  
Average shoot value: **${{ number_format($summary['average_value'] ?? 0, 2) }}**

@if(!empty($summary['commission_rate']))
Commission rate: **{{ $summary['commission_rate'] }}%**  
Projected commission: **${{ number_format($summary['commission_total'] ?? 0, 2) }}**
@endif
@endcomponent

If anything looks off, reply to this email so our accounting team can help before payouts go out.

Thanks for keeping our clients happy!  
— Repro Photos Ops
@endcomponent

