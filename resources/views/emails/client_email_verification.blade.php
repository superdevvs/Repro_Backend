<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address</title>
</head>
<body style="margin:0; padding:0; background:#f8fafc; font-family:Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px; background:#ffffff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden;">
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#0f766e; font-weight:700;">
                                R/E Pro Photos
                            </p>
                            <h1 style="margin:0 0 16px; font-size:28px; line-height:1.2; color:#0f172a;">
                                Verify this client email
                            </h1>
                            <p style="margin:0 0 14px; font-size:16px; line-height:1.7; color:#334155;">
                                Hi {{ $user->name ?: 'there' }}, please verify that <strong>{{ $user->email }}</strong> is the correct email address for this account.
                            </p>
                            <p style="margin:0 0 24px; font-size:15px; line-height:1.7; color:#475569;">
                                Until this email is verified, important outbound messages may stay limited in the dashboard.
                            </p>
                            <p style="margin:0 0 24px;">
                                <a href="{{ $verificationLink }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background:#0f766e; color:#ffffff; text-decoration:none; font-weight:700;">
                                    Verify Email Address
                                </a>
                            </p>
                            <p style="margin:0; font-size:13px; line-height:1.7; color:#64748b;">
                                If the button above does not work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:10px 0 0; font-size:13px; line-height:1.7; color:#0f172a; word-break:break-all;">
                                {{ $verificationLink }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
