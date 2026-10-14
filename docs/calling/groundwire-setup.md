# Groundwire setup for REPro staff

Each person uses their own Dashboard account and their own SIP login. Do not share a SIP login or enter the Telnyx portal password. One enrolled Groundwire phone is supported per Dashboard user; a replacement requires revoking the old device.

## Account assignments

| Person / Dashboard account | Incoming business lines | Choose outgoing caller ID | Incoming office hours |
|---|---|---|---|
| Editing Manager | Editing, 202-780-3332 | +12027803332 | Mon–Fri, 09:00–18:00, Asia/Kolkata |
| Ajay / Jay, Dashboard account Office (superadmin) | Editing and Main | +12028681663 normally; Editing is also allowed | Editing follows India hours; Main follows New York hours |
| Cherisea giddings | Main, 202-868-1663 | +12028681663 | Mon–Fri, 09:00–18:00, America/New_York |

Support, 888-656-7627, continues answering through Robbie 24/7. It is not a separate staff Groundwire account. Authorized superadmins can select Support as an outbound caller ID without changing its inbound Robbie routing. Robbie's 888-804-1663 remains SMS-only.

## Setup — each person repeats these steps on their own phone

1. Install **Groundwire by Acrobits** from the official iOS App Store or Google Play. The app is purchased separately by its owner; no app purchase was made by this release.
2. Open [Dashboard Calls → Settings → Audio & alerts](https://reprodashboard.com/calls/settings) in a browser and sign in with your own staff account.
3. Find **Groundwire mobile phone**. Enter a descriptive device name (for example, Editing Manager iPhone or Cherisea Android), choose the outgoing caller ID from the table above, then select **Set up my Groundwire phone**.
4. Select **Show my SIP login**. The credentials are private to your account and hide after two minutes. Do not send a screenshot of this panel to a group chat. If provider setup is pending, use **Retry same setup**, which reconciles the original request.
5. In Groundwire, open Settings → Accounts / SIP Accounts → Add / New account. Select **Generic SIP account**. Enter the title, username, password, and domain shown in Dashboard. Domain: **sip.telnyx.com**.
6. Open this account's Advanced settings. Set transport **TLS (sip)**, port **5061**. Keep certificate validation enabled. Enable **SRTP** for incoming and outgoing calls. Use G.722, G.711 µ-law (PCMU/G711U), or G.711 A-law (PCMA/G711A); DTMF **RFC 2833**.
7. Set **Incoming calls → Push notifications** so the phone rings in the background and while locked. Acrobits' SIPIS service uses this SIP login to keep it registered and delivers the call notification. Allow Groundwire notifications and microphone access when the phone asks.
8. Keep **Auto-answer off** and **Call waiting off**. Keep **Display calls answered elsewhere** enabled if this option is shown. Save the account.
9. Back in Dashboard, select **Refresh Groundwire registration**. It should show **Registered with Telnyx**. Groundwire does not require an open Dashboard browser tab or its one-hour browser phone token.
10. Test a call to the assigned business line during its working hours. Answer in Groundwire and verify audio in both directions. Repeat with Groundwire in the background and the phone locked, over Wi-Fi and mobile data.
11. Make an outbound call from Groundwire using **+country-code and number**. The Dashboard chooses the saved caller ID and logs the call. Change caller ID in the Groundwire panel before the next call; changing Groundwire's display name does not change the business caller ID.
12. Leave an unanswered test call and verify a playable voicemail in Dashboard. Repeat outside office hours. Keep manual port activation held until the original cutover checks and these phone tests pass.

Exact menu labels can vary between iOS and Android. On iOS start from the keypad's gear; on Android use Settings → Accounts → the account → Advanced settings.

## What happens with multiple accounts and calls

- Dashboard and Groundwire devices for the assigned recipients can ring together. **One atomic winner** gets the call. Every losing SIP leg is cancelled; Dashboard shows who answered. A late answer cannot take the call away from the winner.
- A person already handling a call on any device is skipped for the next incoming call. Other available assigned recipients ring. If none is reachable or the shared 45-second window expires, Dashboard voicemail answers.
- Editing and Main follow their own timezone-aware schedules, including weekends and daylight-saving changes. Support stays with Robbie.
- Messages and voicemail recordings remain in Dashboard. Telnyx SIP does not provide SIP SIMPLE messaging to Groundwire. Do not configure Groundwire's local voicemail or automatic forwarding as a replacement for Dashboard voicemail.
- Use **Phone enabled** to pause new calls to this device. A current call can finish. Revoke the device if the phone is lost or replaced; the provider credential is disabled and later attempts are rejected.

## Troubleshooting

- **Waiting for app registration:** confirm the SIP username/password, sip.telnyx.com, TLS/5061, then save the account and refresh. An app sign-in password is not the SIP password.
- **Rings only while open:** choose Push notifications for that account and enable the phone's notification permission; check Focus / Do Not Disturb settings.
- **Registered but no incoming ring:** check your line assignment, that line's office hours, Phone enabled, staff availability, and whether you already have a live call.
- **Outgoing call rejected:** confirm the saved caller ID is still assigned to you, dial in international format, and check Dashboard's outbound calling mode. Do not add a direct Telnyx outbound trunk profile to bypass these checks.
- **Audio missing:** confirm microphone permission, SRTP/codecs, and test the other network. Device audio and locked-phone push delivery require testing on the actual phone.

## Official setup references

- [Telnyx: Acrobits softphone setup](https://support.telnyx.com/en/articles/5761112-acrobits-softphone)
- [Acrobits: SIP account settings](https://acrobits.net/support/groundwire/settings/sip-account-specific-settings/)
- [Acrobits: incoming call configuration](https://faq.acrobits.net/configuring-incoming-calls)
- [Acrobits: how push notifications work](https://faq.acrobits.net/about-push-notifications)
- [Telnyx: SIP connection and parked outbound calls](https://support.telnyx.com/en/articles/4351104-sip-connection-settings)

## Verification scope

Server and UI regression tests cover credential isolation and encrypted storage, uncertain provisioning reconciliation, registered-device filtering, schedules, browser/SIP first-answer races, busy users, expired offers, caller ID authorization, and shared conference handling. App installation, locked-phone push, and actual phone microphone/speaker checks remain each staff member's setup steps. No port activation is part of Groundwire enrollment.
