# Email Atelier V6

The email presentation follows the approved [Figma email collection](https://www.figma.com/design/kN6Q5oLYfukXmtaOutga1J?node-id=9-80): 51 source types, 72 audience/status variants, 45 illustrations, and desktop/mobile layouts in light and dark.

## Rendering

- `emails.layouts.master` is the shared email document for protected system messages, saved messaging templates, automations, and editable direct notifications.
- `EmailPresentation` applies the approved palette, spacing, semantic detail rows, and buttons to existing dynamic content. The desktop card is 640px within a720px canvas; mobile uses16px outer padding. Explicit preview themes override browser appearance; sent emails use light inline styles with adaptive dark rules.
- `resources/email-atelier/catalog.json` maps email events and variants to committed transparent assets in `public/images/email-atelier/v6`. Each illustration is the same image in both themes. No runtime Figma URLs or generated sample property photos are used.
- `EmailArtwork` handles event/legacy slug aliases and cash/cheque variants. Unknown custom templates retain the shared layout without unrelated artwork.
- `EmailShootPhotos` permits only same-shoot, approved, released, scan-safe derived photos for an identified authorized recipient. Pending, hidden, extra, unverified, unpaid, foreign-shoot and missing media fall back to the media-access illustration.

## Editing

Saved and unsaved previews use the same server renderer as sending. The editor has light/dark and720/375px controls. The body region is marked `data-email-content="true"`, allowing an older full HTML document to reopen as editable content without duplicating the masthead or footer.

Subject, HTML, plain text and declared variables are preserved through save/reopen. Template-specific dynamic HTML blocks retain live report tables and account details; their editor previews contain fictional examples. Existing protected-email outcome, recipient, payment, fallback and override rules continue to apply.

New template registration is additive. Existing custom copy, disabled templates and automation configuration are not reset. Image selection and shared presentation are maintained centrally, so editing message copy does not require pasting a second email shell.

## Verification and release

Run the backend `composer quality` gate and the frontend `npm run quality` gate with lockfile dependencies. Regression coverage includes actual notification/CRM/invoice send paths with intercepted mail, protected outcomes, editor draft/save/reopen behavior, contact details, theme contrast, asset coverage and photo-release eligibility.

The release render inventory checks all72 variants across720/375px and light/dark, with original image bytes and external network blocked. Browser checks include content-region count, image loading, and element bounds. Chromium verification does not replace testing in every receiving email application.

Deploy backend assets/templates first, then the frontend editor, using the prepared release workflow. Verify exact deployed commits, migration completion, public image responses and live template preview behavior. Never run the complete messaging seeder merely to apply this design.
