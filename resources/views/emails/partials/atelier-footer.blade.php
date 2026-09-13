<tr><td class="email-inset footer-inner" data-email-footer="atelier" style="padding:36px 48px;border-top:1px solid {{ $colors['border'] }};">
    <p class="atelier-link" style="margin:0 0 12px;color:{{ $colors['accent'] }};font-size:10px;line-height:16px;font-weight:600;letter-spacing:1.8px;text-transform:uppercase;">Here for you</p>
    <p class="dark-heading" style="margin:0 0 16px;color:{{ $colors['ink'] }};font-size:23px;line-height:31px;font-weight:500;letter-spacing:-.6px;">Need help with a shoot, invoice, or account question?</p>
    <p class="dark-body footer-contact" style="margin:0;color:{{ $colors['body'] }};font-size:13px;line-height:24px;">Call <a class="footer-contact-link" href="tel:{{ $supportPhoneHref }}" style="color:{{ $colors['accent'] }};text-decoration:underline;">{{ $supportPhone }}</a><br><a class="footer-contact-link" href="mailto:{{ $supportEmail }}" style="color:{{ $colors['accent'] }};text-decoration:underline;">{{ $supportEmail }}</a></p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
        <tr>
            <td class="footer-rule" style="padding:18px 0;border-top:1px solid {{ $colors['border'] }};">
                <a class="dark-heading" href="{{ $websiteUrl }}" style="display:block;color:{{ $colors['ink'] }};font-size:14px;line-height:22px;font-weight:600;text-decoration:none;">Website</a>
                <p class="dark-muted" style="margin:3px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:20px;">View products and services to order.</p>
            </td>
            <td width="32" align="right" class="footer-rule" style="padding:18px 0;border-top:1px solid {{ $colors['border'] }};vertical-align:middle;"><a class="atelier-link" href="{{ $websiteUrl }}" aria-label="Visit our website" style="color:{{ $colors['accent'] }};font-size:22px;line-height:28px;text-decoration:none;">&#8599;</a></td>
        </tr>
        <tr>
            <td class="footer-rule" style="padding:18px 0;border-top:1px solid {{ $colors['border'] }};border-bottom:1px solid {{ $colors['border'] }};">
                <a class="dark-heading" href="{{ $brand['review_url'] }}" style="display:block;color:{{ $colors['ink'] }};font-size:14px;line-height:22px;font-weight:600;text-decoration:none;">Leave a Review</a>
                <p class="dark-muted" style="margin:3px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:20px;">We are looking for 5 stars and nothing less.</p>
            </td>
            <td width="32" align="right" class="footer-rule" style="padding:18px 0;border-top:1px solid {{ $colors['border'] }};border-bottom:1px solid {{ $colors['border'] }};vertical-align:middle;"><a class="atelier-link" href="{{ $brand['review_url'] }}" aria-label="Leave a review" style="color:{{ $colors['accent'] }};font-size:22px;line-height:28px;text-decoration:none;">&#8599;</a></td>
        </tr>
    </table>
    @hasSection('footer_note')
        <p class="dark-muted" data-email-footer-note="true" style="margin:20px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:20px;">@yield('footer_note')</p>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;">
        <tr>
            <td class="footer-brand-column" style="vertical-align:middle;">
                <a href="{{ $websiteUrl }}" style="text-decoration:none;border:0;"><img class="email-logo-universal" src="{{ $emailLogoUrl }}" alt="{{ $productName }}" width="126" style="display:block;width:126px;max-width:126px;height:auto;border:0;"></a>
            </td>
            <td class="footer-social-column" align="right" style="vertical-align:middle;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;"><tr>
                    @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'] as $network => $label)
                        <td style="padding-left:10px;"><a href="{{ $brand['social_'.$network.'_url'] }}" aria-label="{{ $label }}" target="_blank" rel="noopener" style="display:block;text-decoration:none;border:0;"><img src="{{ $brand['social_'.$network.'_icon_url'] }}" alt="{{ $label }}" width="28" height="28" style="display:block;width:28px;height:28px;border:0;"></a></td>
                    @endforeach
                </tr></table>
            </td>
        </tr>
    </table>
    <p class="dark-heading" style="margin:24px 0 10px;color:{{ $colors['ink'] }};font-size:15px;line-height:23px;font-weight:500;">Thank you for the opportunity.</p>
    <p class="dark-muted" style="margin:0;color:{{ $colors['muted'] }};font-size:11px;line-height:19px;">This email was sent by {{ $productName }}. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.</p>
    @if(!empty($brand['company_address']))
        <p class="dark-muted" style="margin:12px 0 0;color:{{ $colors['muted'] }};font-size:11px;line-height:19px;">{{ $brand['company_address'] }}</p>
    @endif
</td></tr>
