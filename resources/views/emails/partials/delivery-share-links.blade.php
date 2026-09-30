@php
    $deliveryShare = $deliveryShare ?? null;
    $shareLinks = is_array($deliveryShare['links'] ?? null) ? $deliveryShare['links'] : [];
    $shareNote = is_string($deliveryShare['completed_shoots_note'] ?? null)
        ? $deliveryShare['completed_shoots_note']
        : 'You can also sign in to your client account and open this shoot under Completed Shoots.';
@endphp

@if(!empty($shareLinks))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Share &amp; Download Links</p>
                <p class="dark-body" style="margin:0 0 14px; font-size:14px; line-height:1.7; color:#47627f;">Forward these links — no login required. Downloads unlock after the shoot is paid in full.</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    @foreach($shareLinks as $link)
                        <tr>
                            <td class="detail-border dark-heading" style="padding:12px 12px 12px 0; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.55; font-weight:700; vertical-align:top; width:42%;">{{ $link['label'] }}</td>
                            <td class="detail-border dark-body" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#1463ff; font-size:13px; line-height:1.55; word-break:break-all; vertical-align:top;">
                                <a href="{{ $link['url'] }}" style="color:#1463ff; text-decoration:underline; font-weight:600;">{{ $link['url'] }}</a>
                            </td>
                        </tr>
                    @endforeach
                </table>
                <p class="dark-muted" style="margin:14px 0 0; font-size:13px; line-height:1.6; color:#6f86a4;">{{ $shareNote }}</p>
            </td>
        </tr>
    </table>
@endif
