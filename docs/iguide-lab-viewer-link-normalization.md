# Copied iGUIDE viewer links

The import form may accept an optional `pano` query parameter as a viewer-only position. The backend removes it before constructing the whole-tour page URL, archive URL and import request identity. For example, `/sample/?pano=13` and `/sample/?pano=29` both identify `/sample/` for import.

Frontend validation should mirror the backend without interpreting the access token:

- Reject raw control characters before URL parsing, which could otherwise silently alter a token. Keep the existing HTTPS `youriguide.com` host, tour path, user-info, port and fragment restrictions and the existing 5000-character request URL limit.
- Allow at most one decoded `pano` key and at most one decoded `accessToken` key; reject duplicates, arrays and unknown keys. Each query field needs `=` and valid percent encoding. Do not use a parser that silently collapses repeated keys.
- `pano` is 1–10 ASCII decimal digits, with numeric value from 0 through 2147483647 inclusive. No sign, spaces, decimal point or exponent. It is not a panorama selection for this whole-tour importer.
- Preserve a single optional access token exactly after normal query decoding and canonical RFC3986 encoding. Its existing maximum is 4096 decoded bytes; control characters are invalid. Different tokens remain different request identities. Never include the token in public labels or URLs.

The request/response DTO does not change. The frontend can submit the validated original URL; the backend owns canonical import identity. Replaying one request UUID with a different valid `pano` returns the same import; changing the token returns 409. Public URLs stay query-free. Download authorization and redirect refusal are unchanged: a provider redirect or protected archive still requires the original ZIP, never a guessed or bypassed route.
