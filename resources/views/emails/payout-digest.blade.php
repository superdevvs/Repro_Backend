@component('mail::message')
# Weekly payout approvals digest

Period: **{{ $rangeStart->format('M d') }} – {{ $rangeEnd->format('M d, Y') }}**

@component('mail::table')
| Photographers | Shoots | Gross |
| :------------ | :-----: | ----: |
@foreach($photographers as $row)
| {{ $row['name'] }} | {{ $row['shoot_count'] }} | ${{ number_format($row['gross_total'], 2) }} |
@endforeach
@endcomponent

**Total photographer payout:** ${{ number_format($totalPhotographerPayout, 2) }}

@component('mail::table')
| Sales reps | Shoots | Gross | Commission |
| :--------- | :-----: | ----: | ---------: |
@foreach($reps as $row)
| {{ $row['name'] }} | {{ $row['shoot_count'] }} | ${{ number_format($row['gross_total'], 2) }} | ${{ number_format($row['commission_total'] ?? 0, 2) }} |
@endforeach
@endcomponent

**Total rep commission:** ${{ number_format($totalRepPayout ?? 0, 2) }}

Please review and approve so accounting can release payments. Let us know if anything needs adjustment.

Thanks!  
— Ops Bot
@endcomponent

