# CFE (Uruguay) emission on send: specification

Status: draft for review, 2026-10-27.
Branch: `cfe-uy/rebase-v5-stable` (fork rebased onto upstream `v5-stable`).

## 1. Scope

Invoice Ninja emits a Uruguayan CFE (e-Ticket 101 or e-Factura 111) through xml-cfe when an invoice leaves draft state by being sent. The PDF the client receives carries the CAE, the series and number, and the DGI QR code. If emission fails, no email is sent.

Out of scope: mcp-invoiceninja, quotes, purchase orders, multi-currency rules beyond what the current `Mapper` supports.

## 2. Terms

- **Emitted**: xml-cfe returned success and the invoice has a `cfe_logs` row with `provider_status` of a successful state and a CAE. The marker is `invoice.backup.guid != ""`.
- **Primed**: an invitation with `email_error = 'primed'`. Primed invitations are held until emission succeeds. This is the upstream Verifactu mechanism.
- **CFE company**: a company with `settings.e_invoice_type == 'CFE_UY'` (`Company::cfeUyEnabled()`).

## 3. Rules

1. Drafts never emit. Editing and deleting drafts is unrestricted.
2. Every path that moves an invoice out of draft (P1–P8 in section 4) for a CFE company emits exactly once. A second trigger for an emitted invoice is a no-op.
3. For every path that sends email, all invitations are primed before emission is dispatched. `SendToCfeUy` releases them on success.
4. On permanent failure, invitations stay primed, no email leaves, and the failure is visible in the activity log and in the UI (section 8).
5. After emission the invoice is locked: no edits to lines, amounts, client or date. Payments remain allowed.
6. An emitted invoice cannot be undone by deleting it. Reversal requires a CFE credit note (section 7).

## 4. Send paths

Each path below is listed with its upstream location and the required CFE behaviour. "Prime + emit" means: prime all invitations, call `$invoice->service()->markSent()->sendCfeUy()`, and skip the direct email.

| # | Path | Location | Fork today | Required |
|---|------|----------|-----------|----------|
| P1 | Save and send (web, API `?send_email=true`) | `TriggeredActions.php:60` | Prime + emit | Keep |
| P2 | Email action (web "Send email", API `/emails`) | `EmailController.php:76` | Not hooked, email sent without CFE | Prime + emit |
| P3 | Bulk send / bulk email | `BulkInvoiceJob.php:82` | Not hooked | Prime + emit |
| P4 | Scheduled email (scheduler `email_record`) | `Scheduler/EmailRecord.php:34` | Not hooked | Prime + emit |
| P5 | Recurring auto-send | `RecurringInvoice/SendRecurring.php:120-146` | Event hook emits after the email has already been dispatched | Prime + emit in place of `sendRecurringEmails()` |
| P6 | Mark sent without email (web, API `?mark_sent=true`, bulk `mark_sent`) | `BaseModel.php:263` via `EVENT_SENT_INVOICE` | Emits | Emit, nothing to prime |
| P7 | Retry e-invoice (`?retry_e_send=true`) | `TriggeredActions.php:94` | Emits | Keep, and also re-send any primed invitations |
| P8 | Mark paid on a draft (web, API `?paid=true`, bulk `mark_paid`) | `MarkPaid.php:59` sets `STATUS_SENT` directly and fires no `EVENT_SENT_INVOICE` | Not hooked, invoice becomes sent and paid with no CFE | Emit after the status change, nothing to prime. Payment receipt emails wait for emission like invoice emails |
| P9 | Reminders and portal views | reminder jobs, client portal | Not applicable | Reminders only run for sent invoices. Block reminders for a CFE company while `backup.guid == ""` |

Upstream Verifactu does not prime in P5. CFE must, because the recurring email would otherwise leave without the CAE.

### Double dispatch

P1–P5 call `markSent()`, which fires `EVENT_SENT_INVOICE`, and the `BaseModel` hook (P6) dispatches a second `SendToCfeUy`. The job must be safe under this:

- Make `SendToCfeUy` implement `ShouldBeUnique` with `uniqueId = company_key . invoice_id`.
- Keep the `backup.guid` check at the start of `handle()`, and re-read the invoice with a row lock (`lockForUpdate` inside a transaction) before calling xml-cfe.
- xml-cfe must also be idempotent on `external_invoice_id` (the invoice `hashed_id`). To confirm in #xml-cfe.

## 5. Emission job (`SendToCfeUy`)

Changes from the current fork:

1. Success marker: set `backup.guid` only from a CAE-backed result. Today it falls back to the literal `'cfe_uy_emitted'`, which would hide a malformed response. Store `cfe_tipo`, `cfe_serie`, `cfe_numero` in `backup` as well so the PDF and UI do not need a join.
2. Non-retryable failure (validation error, DGI rejection): write activity `CFE_UY_INVOICE_SENT_FAILURE`, keep invitations primed, do not throw. Set `backup.cfe_status = 'rejected'` with the provider message.
3. Retryable failure: backoff `[5, 30, 240, 3600, 7200]` seconds, 5 tries, as today. After the last try, `failed()` sets `backup.cfe_status = 'failed'` and writes the failure activity.
4. On success, after releasing emails: purge any cached PDF for the invoice so the next download includes the CAE.
5. Activity and system-log IDs moved to a reserved range during the rebase: activities 900–903, system-log events 900–901. Upstream had reused 158–161 and 74.

## 6. PDF and template variables

Add to `HtmlEngine` (next to `$verifactu_qr_code`), empty unless the invoice is emitted:

| Variable | Content |
|----------|---------|
| `$cfe_qr_code` | `<img>` of `imagen_qr_base64`, or a QR generated from `link_qr` |
| `$cfe_cae` | CAE number, range `cae_dnro`–`cae_hnro`, expiry `cae_vto` |
| `$cfe_series_number` | `serie` + `numero` |
| `$cfe_type` | "e-Ticket" or "e-Factura" |
| `$cfe_security_code` | first 6 characters of `hash` (DGI "código de seguridad") |
| `$cfe_legend` | the fixed DGI legend ("Puede verificar comprobante en www.dgi.gub.uy", IVA al día) |

The company design must place these in the footer. The DGI layout rules for printed representation (Formato de representación impresa) are the reference. The exact legend text is checked against the DGI spec before release.

## 7. Delete, cancel and reverse after emission

| Action | Draft | Emitted |
|--------|-------|---------|
| Edit | Allowed | Blocked (add CFE to the `UpdateInvoiceRequest.php:121` lock) |
| Delete | Allowed | Blocked with a message pointing to cancel |
| Archive | Allowed | Allowed (no tax effect) |
| Cancel | Allowed | Emits a CFE credit note (102 for e-Ticket, 112 for e-Factura) referencing the original, then marks the invoice cancelled |
| Reverse | n/a | Same as cancel, plus Invoice Ninja's credit |

Credit notes depend on xml-cfe supporting CFE 102 and 112 with a reference to the original CFE. This is the first open item to confirm in #xml-cfe. Until it is supported, cancel and reverse on emitted invoices are blocked.

The hook points are the Verifactu ones: `ActionsInvoice.php` (`invoiceDeletable`, `invoiceCancellable`, `invoiceReversable`), `HandleCancellation.php:38`, `InvoiceService::markDeleted` (`:241`).

## 8. Web UI (fork `pabloi/ui`)

1. Settings > E-Invoice: add `CFE_UY` to the type options (`src/pages/settings/e-invoice/EInvoice.tsx:65`). Show xml-cfe connection status (read-only, from a new `GET /api/v1/einvoice/cfe_uy/health` endpoint).
2. Invoice page: a CFE panel with status (not emitted, pending, emitted, rejected, failed), type, series and number, CAE, link to the DGI QR URL, and the failure message.
3. Invoice actions: "Retry CFE" when status is rejected or failed (calls P7). Hide Delete on emitted invoices.
4. Activity log: labels for activity types 900–903.
5. Saving the E-Invoice page must not reset `e_invoice_type`. The backend already preserves `CFE_UY` (`UpdateCompanyRequest.php:250`). Verify with the UI fork.

The UI ships inside the backend release (`public/`), so the backend build must bundle the forked UI.

## 9. Build, deploy and drift check

To be written once the host is confirmed. Contents: build of `pabloi/ui` into `public/`, Docker or bare deploy, migrations (`cfe_logs`), environment (`CFE_UY_BASE_URL`, `CFE_UY_TOKEN`), and a weekly check that the running version is the fork's build tag.

## 10. Cutover

1. Deploy the fork with `CFE_UY_ENABLED=false`. Smoke test against the SICFE test environment.
2. Set `e_invoice_type = CFE_UY`.
3. Turn off the xml-cfe `invoice-created` webhook in the same window, so no invoice gets two CFEs.
4. Send one real e-Ticket and one e-Factura. Verify with mcp-sicfe.

## 11. Tests

- Feature test per send path P1–P8: invitations primed, one job dispatched (with `Queue::fake`), no email before success, emails released after success.
- Double-dispatch test: P1 plus the event hook produces one xml-cfe call.
- Recurring test: auto-send for a CFE company sends no email before emission.
- Lock tests: edit, delete, cancel on emitted invoices.
- HtmlEngine test: variables empty before emission, filled after.

## 12. Open items

1. Hosting target (section 9).
2. xml-cfe: idempotency on `external_invoice_id`, and support for CFE 102/112 credit notes.
3. Mark sent (P6): this spec emits on mark sent, since a sent invoice is a fiscal document. Confirm.
4. Invoices already sent before cutover, emitted by the xml-cfe webhook: backfill `backup.guid` and `cfe_logs` from xml-cfe so they are treated as emitted.
