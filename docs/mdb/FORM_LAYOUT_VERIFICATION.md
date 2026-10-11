# Survey entry layout and safe copying — 11 October 2026

The user's green-marked `Form.jpg` supplied the transcription layout reference. No handwritten value or marking in the attachment was treated as a command or imported into application records.

The S/E editor now groups row identity, phase/conductors/equipment, consumer counts and PV in PDF order. Calculated fields are omitted from Tab order. Pairing, complete-waypoint tools and explanatory text remain available in expandable controls. Desktop groups wrap according to available form width; mobile and short windows allow the PDF viewer to scroll away. The initial viewing height is 240 px, preserving saved preferences.

Copying uses an explicit reusable-field allowlist: R/Y/B/neutral, equipment type, pole class, and height with a known unit. It preserves entered values when the previous value is blank and asks before replacing any entered attribute. Cancellation makes no changes. IDs, coordinates, sources, pairing, consumer counts, PV and intersection are excluded. Copy sources are scoped to preceding rows in the active transformer; editing clears copy provenance. The previous-waypoint copy button was removed. Pasting an explicitly supplied complete waypoint also asks before replacing entered components.

Validation allows incomplete drafts but rejects malformed dates/identifiers, invalid conductor codes, and out-of-range or fractional consumer counts. Client and backend validation display field-specific messages. Searchable controls expose invalid state and error descriptions on their visible trigger; nested PV errors identify the matching input. Corrections clear their resolved field errors. Invalid/failed/unconfirmed saves retain the draft and do not advance. Confirmed Save & Add Next updates the saved-row check and persistent physical PDF marker, selects the next pending marker, and focuses the next missing identity field after enabling it.

Verification:

- Four PHP feature suites: **79 tests, 817 assertions**, all passed. Includes backend invalid-field messages, no record/progress change on failure, role restrictions, persistent PDF progress and existing export behavior.
- JavaScript suite: **13 tests**, all passed. Includes confirmation cancellation, height copying/editing, identifier preservation, field focus, invalid draft retention, successful next-row focus, readonly behavior and growing PDF progress.
- Local headless Chrome: **1440 × 1080**, **1100 × 900**, **390 × 844**, and **1280 × 900 with the menu open**. No page overflow or overflowing field controls. Actual Tab navigation followed the editable PDF columns; Tab from an open conductor search focused the following conductor. Inline validation focused the invalid count. No JavaScript page errors.
- Scoped PHP formatting and `git diff --check` passed.

Chrome used rendered feature-test HTML, the application's CSS/JavaScript, an isolated source/record fixture and a viewer stub displaying the reference image. The in-app browser was unavailable. No live survey data was entered or copied during these checks; live authenticated browser testing remains separate.
