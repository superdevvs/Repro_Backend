{!! $body !!}
@if($hasCta)
    <p style="margin:24px 0 0;"><a class="button" href="{{ $ctaUrl }}">{{ $ctaLabel }}</a></p>
@endif
{!! $signatureHtml !!}
