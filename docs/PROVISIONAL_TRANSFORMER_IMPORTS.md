# Provisional KMZ transformer registration

KMZ import now registers structurally valid transformer points even when their client codes repeat or their capacity is zero/missing. Source attributes and the uploaded KMZ remain unchanged.

- Repeated codes receive separate working codes containing `~PENDING-`, the source feature ID (when supplied), and a unique suffix. Every point has its own permanent database ID and retains its original client code, GPS reference, location and capacity.
- A zero/missing rating is stored as unknown (`NULL`), with the original source value retained separately. A positive rating is never invented.
- Invalid geometry or missing required identity/source information still produces a warning and skips that point. Other valid points import. Warning/provisional imports never delete existing records.
- The import result displays synchronized, pending-verification and skipped counts. Source point counts are distinct from verification flags: one transformer may have both flags.

## Where to resolve records

Open **Transformer GIS Data**, filter **Verification** to **Client code confirmation pending** or **Capacity verification pending**, then open **View**. The Filament transformer view also has a **Reference verification / corrections** link.

**Client codes:** Super Admin enters the official code, a reason, and the client's letter/email reference. The code must be unique on that feeder; a code/history belonging to another transformer is rejected. The permanent ID and historic aliases remain available. Field users cannot confirm official client codes.

**Capacity from a field visit:** an assigned surveyor/mobile survey user/team leader records a positive capacity, visit notes and a nameplate/field photograph. Submitted mobile surveys for unknown-capacity transformers also create observations automatically from their entered header rating. Their supporting row photographs remain linked to the source survey.

A scoped Data Verifier or Super Admin reviews the photograph and approves or rejects the observation. The observer cannot approve their own observation. Missing/changed evidence and stale capacity observations are rejected. Confirming a code does not invalidate an otherwise current capacity observation: code and rating reviews are independent.

An approved capacity updates the reference rating and records the reviewer/time. Client confirmations and capacity approvals have a correction ledger with before/after values, reason, evidence reference and actor. Original KMZ values remain available.

## Work and export behaviour

Assignments, field surveys, photos and PDF entry can continue using provisional references. PDF transformer headers may save a blank capacity only for a linked registration pending field verification. Final engineering approval and MDB generation require a resolved code and verified positive rating for the selected transformer. A separately selected resolved transformer can export even if another registration remains pending; consolidated exports must resolve every included transformer.

Field survey observations and photo/row identities are preserved when a client code changes. Modelling uses the confirmed registration linked by permanent ID; historical working codes remain recognizable. Linked PDF work keeps its transformer/section/row IDs and original header, receives the corrected reference values and requires verification again. Existing frozen revisions remain historic; current outputs are superseded using the established batch revision workflow.

Reimporting the original KMZ matches source identities and retains approved local code/rating corrections. It cannot silently clear a pending flag. Changed source identities that could move a provisional/corrected transformer to another point require reconciliation. The existing protection against replacing an HT baseline with field updates remains; an identical source file can be reimported without changing its identity.

Existing registrations default to confirmed; existing raw observations are not backfilled or renamed by the migration. Apply the additive migration with `php artisan migrate` on each installation before deploying the updated application files. Changes in this workspace do not deploy the hosted website or import the client file into its live feeder.
