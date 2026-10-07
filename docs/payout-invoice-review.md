# Weekly payout invoice correction workflow

Standard photographer shoot payouts use Sunday–Saturday weeks based on the scheduled shoot date. Completion/verification is an eligibility gate, not the date that assigns the week. A Saturday shoot completed Sunday belongs to Saturday's week. Sales-rep shoot commissions already use shoot dates. Explicit compensation adjustments/reversals and editor labor retain their separate earned-date rules; historical invoices are not automatically migrated.

## Review and correction

- **View invoice** only reads records. **Confirm invoice is correct** submits unchanged invoices; a note is optional.
- **Edit invoice** saves audited line changes, expenses and linked shoot services. Modified invoices require a nonblank explanation at submission, regardless of which API submission route is used.
- **Add shoot** selects a completed/verified, unpaid service assigned to the invoice recipient and uses its configured payout, per-service override, unit tier and quantity. A service shot outside the billing week requires a reason. Allocation prevents it paying again the next week. View/edit lines show work dates newest first, with missing dates last, without duplicating the shoot number.
- **Add external / legacy work** requires original job ID/URL, work date, address, description, payout and explanation. Legacy reference formats are normalized. Accounts must verify the external work or record a warning override before approval.
- Accounts can correct unpaid/unapproved invoices with a mandatory reason, then approve the current revision. Approved, paid and partially paid invoices are locked. Payees cannot edit while accounts review their submission.
- Review queue filters distinguish awaiting payee, submitted, returned and approved. Submitted badges distinguish confirmed unchanged, changes submitted and accounts corrected. Return reasons remain in history after resubmission.
- Generation never replaces manually edited, submitted, returned, approved or paid invoices. Queue reads never generate invoices. Submission and approval snapshots are frozen separately; edits retain before/after snapshots.

## Existing affected invoices (read-only investigation on 2026-10-07)

- W-692, Delmar Harrod: two manually entered $78.75 lines were removed by regeneration. Dudley is dashboard shoot 641, shot October 3 and completed October 4; under the corrected rule its invoice week is September 27–October 3. Edgemere is excluded legacy job `viewshoot:1626:466152` and has no dashboard shoot.
- W-686, Jaz Singh: the $78.75 Gleneagles addition was removed and the submission had no note. Dashboard shoot 379 completed October 4, but its service is **30 HDR Photos + Floor plans**, not the manually entered 25 HDR line. Verify the actual payout before correcting.
- W-682, KK Kumnith Keo: a removed Twilight line was restored, and a $35 manually added travel charge was deleted by regeneration. Reconcile the intended $471.50 against the regenerated $504.00 before approval.
- W-693, Marisa Bandy: unchanged confirmation without a note was legitimate; it should not be presented as a changes request.

Do not automatically restore historical totals or invent notes. Use the audit history, original work and prior payout records to verify each correction. The editor flags suspected historical regeneration loss even when subsequent regeneration leaves totals unchanged. Accounts must review all historical edits and explicitly check the reconciliation acknowledgement when saving a correction; another ordinary edit does not clear this warning.

After deploying code and migration, the following command produces JSON only; it neither changes amounts nor sends notifications:

```sh
php artisan invoices:audit-payout-edits --invoice=692 --invoice=686 --invoice=682
```

Production reconciliation is a separate financial action. The implementation itself does not modify these invoices.
