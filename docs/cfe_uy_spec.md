# CFE (Uruguay) emission on send: specification

Status: draft for review, 2026-10-27. Decisions from pablo (2026-10-27): mark sent emits; UI confirms before any CFE-emitting action; delete always blocked after emission; cancel admin-only with warning until credit notes exist.
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

P2 limitation (accepted): on the first send, which emits the CFE, the email action ignores the request's custom subject, body, template and `cc_email`. The released mail uses the default invoice template. Upstream Verifactu behaves the same (`EmailController.php:76`). Later sends of an emitted invoice use the normal email path with all options.

### Double dispatch

P1–P5 call `markSent()`, which fires `EVENT_SENT_INVOICE`, and the `BaseModel` hook (P6) dispatches a second `SendToCfeUy`. The job must be safe under this:

- Make `SendToCfeUy` implement `ShouldBeUnique` with `uniqueId = company_key . invoice_id`, and set `uniqueFor` above the total backoff span, so a lock left by a dead worker expires.
- Code outside the job that writes `backup` (for example P8 queuing payment ids) re-reads the invoice with `lockForUpdate` first. `backup` is saved as one JSON column, so a write from a stale copy erases the job's `guid` and `cfe_*` fields.
- Keep the `backup.guid` check at the start of `handle()`, and re-read the invoice with a row lock (`lockForUpdate` inside a transaction) before calling xml-cfe.
- xml-cfe must also be idempotent on `external_invoice_id`. It is not today (section 5a).

## 5. Emission job (`SendToCfeUy`)

Changes from the current fork:

1. Success marker: set `backup.guid` only from a CAE-backed result. Today it falls back to the literal `'cfe_uy_emitted'`, which would hide a malformed response. Store `cfe_tipo`, `cfe_serie`, `cfe_numero` in `backup` as well so the PDF and UI do not need a join.
2. Non-retryable failure (validation error, DGI rejection): write activity `CFE_UY_INVOICE_SENT_FAILURE`, keep invitations primed, do not throw. Set `backup.cfe_status = 'rejected'` with the provider message.
3. Retryable failure: backoff `[5, 30, 240, 3600, 7200]` seconds, 5 tries. Automatic retry is allowed only against the idempotent `POST /v1/cfe/emit` of section 5a. A timeout or 5xx can happen after SICFE accepted the CFE, so without idempotency a retry can emit a second CFE. After the last try, `failed()` sets `backup.cfe_status = 'failed'` and writes the failure activity.
4. On success, after releasing emails: purge any cached PDF for the invoice so the next download includes the CAE.
5. Activity and system-log IDs moved to a reserved range during the rebase: activities 900–903, system-log events 900–901. Upstream had reused 158–161 and 74.

## 5a. xml-cfe contract

Answers from #xml-cfe (2026-10-27, read from xml-cfe source):
- The fork's `Client` calls `POST /v1/cfe/emit` and `GET /v1/cfe/status/{external_id}`. Neither exists in xml-cfe.
- `POST /v1/cfe/process` is not idempotent. With `submit_to_sicfe: true` every call submits to SICFE again.
- `POST /v1/invoice-ninja/emit/{invoice_id}` replays a stored success per tenant and invoice, but it fetches the invoice from Invoice Ninja itself, writes `custom_value1..4` back, can send the Invoice Ninja email, stores dry runs as success, and is not exactly-once under concurrency. It does not fit this design.
- CFE 102 and 112 are not supported.
- Emission data sits under `sicfe.*` (`datos_cae.nauto/dnro/hnro/fvto`, `hash_cfe`, `link_qr`, `id_cfe.serie/numero`, `codigo`). Missing SOAP fields default to empty strings or zero, so HTTP 200 alone is not success.

Decision: add the two endpoints the fork already calls to xml-cfe, with these semantics.

`POST /v1/cfe/emit` (header `X-Tenant-Id`, body: the `Mapper` payload, which includes `external_invoice_id`):
1. Key: `(tenant, external_invoice_id)`. A unique row is inserted with state `pending` before SICFE is called. A concurrent second call finds the row and returns `409` with state `pending`.
2. On SICFE acceptance the row stores the CAE data and state `emitted`. A later call with the same key returns the stored result without calling SICFE.
3. On SICFE rejection the row stores state `rejected` and the SICFE code and message. A later call may resubmit.
4. If the SICFE call times out or the outcome is unknown, the row stays `pending`. xml-cfe resolves it by querying SICFE for the CFE before allowing another submission.
5. The response is flat: `success`, `status`, `message`, `trace_id`, `retryable`, `cfe {tipo, serie, numero, cae_id, cae_dnro, cae_hnro, cae_vto, hash, link_qr, imagen_qr_base64, xml_firmado}`, `provider {codigo, descripcion}`. `success` is true only when `sicfe.codigo` is the success code and `cae_id`, `serie` and `numero` are non-empty.

`GET /v1/cfe/status/{external_id}` returns the same body for the stored row, or `404`.

Invoice Ninja side: `ResponseProcessor` keeps parsing the flat body and treats `409 pending` as retryable. Until xml-cfe ships these endpoints, Invoice Ninja is tested against a faked HTTP client only.

## 6. PDF and template variables

Add to `HtmlEngine` (next to `$verifactu_qr_code`), empty unless the invoice is emitted:

| Variable | Content |
|----------|---------|
| `$cfe_qr_code` | `<img>` of `imagen_qr_base64`, or a QR generated from `link_qr` |
| `$cfe_cae` | CAE number, range `cae_dnro`–`cae_hnro`, expiry `cae_vto` |
| `$cfe_series_number` | `serie` + `numero` |
| `$cfe_type` | "e-Ticket" or "e-Factura" |
| `$cfe_security_code` | first 6 characters of `hash` (DGI "código de seguridad") |
| `$cfe_legend` | Ordinal 22 legend: "Fecha emisor" + `cfe_uy_issuer_since`, then the verification phrase. Types 101/102/103 (e-Ticket and its notes): "Puede verificar comprobante en " + `cfe_uy_verification_url`. Types 111/112/113: "Puede verificar comprobante en www.dgi.gub.uy". If an e-Ticket legend is needed and the URL setting is empty, log a warning and render the DGI phrase |
| `$cfe_iva_al_dia` | `cfe_uy_iva_al_dia_text` verbatim, empty when unset (ordinal 23, "si corresponde": never inferred from emission) |

Source: Res. DGI 798/2012, numeral 14, ordinals 20–25, with ordinal 22 as replaced by Res. DGI 9.834/2018, numeral 3 (https://www.impo.com.uy/bases/resoluciones-dgi-interes-general/798-2012/1, read 2026-10-28).

Company settings (no issuer data in code): `cfe_uy_verification_url`, `cfe_uy_issuer_since` (incorporation date, or the resolution number for issuers incorporated by resolution), `cfe_uy_iva_al_dia_text`. Values are set at deploy.

The company design must place these in the footer. The DGI layout rules for printed representation (Formato de representación impresa) are the reference.

## 7. Delete, cancel and reverse after emission

An emitted CFE cannot be deleted at DGI. Any invoice with a CFE stays in Invoice Ninja permanently.

| Action | Draft | Emitted |
|--------|-------|---------|
| Edit | Allowed | Blocked (add CFE to the `UpdateInvoiceRequest.php:121` lock) |
| Delete | Allowed | Always blocked |
| Restore | Allowed | Archived invoices only (Verifactu rule in `invoiceRestorable`) |
| Archive | Allowed | Allowed (no tax effect) |
| Cancel | Allowed | Phase 1: admin only, after a warning. Phase 2: emits a credit note |
| Reverse | n/a | Blocked (Verifactu blocks it too, `invoiceReversable`) |

### How upstream handles this for Verifactu

`HandleCancellation::verifactuCancellation` (`app/Services/Invoice/HandleCancellation.php:77`):

1. Sets the original invoice to `STATUS_CANCELLED`.
2. Replicates it as a new invoice with negated quantities and surcharges, `backup.document_type = 'R2'`, `backup.parent_invoice_id` and `backup.parent_invoice_number` pointing to the original, and the cancel reason in `backup.notes`.
3. Saves the copy, marks it sent and submits it to AEAT.
4. Appends the copy's id to the original's `backup.child_invoice_ids`.

A cancellation document cannot itself be cancelled. Partial corrections (R1) are new invoices created with `modified_invoice_id` in the request (`InvoiceService::modifyVerifactuWorkflow`, `VerifactuAmountCheck`). The credit note is therefore a negative invoice linked through `backup`. Invoice Ninja's separate Credit entity is not used.

### CFE design

Phase 1 (before xml-cfe supports credit notes):
- Cancel on an emitted invoice is allowed only for admins (`user->isAdmin()`), enforced in the backend.
- The UI shows a warning first: the CFE stays valid at DGI, a nota de crédito must be issued separately (for example in the SICFE portal), and the admin confirms. The cancel reason is required and stored in `backup.notes`.
- Activity log records who cancelled and the reason.

Phase 2 (after xml-cfe supports 102 and 112, which it does not as of 2026-10-27):
- Cancel follows the Verifactu model: replicate as a negative invoice, `backup.document_type = '102'` (from e-Ticket 101) or `'112'` (from e-Factura 111), linked through `parent_invoice_id` / `child_invoice_ids`, and emitted with a reference to the original's type, series and number.
- Partial credit notes use `modified_invoice_id`, as R1 does.
- The admin-only restriction and warning are dropped for cancel, since the credit note is automatic.

Hook points: `ActionsInvoice.php` (`invoiceDeletable`, `invoiceRestorable`, `invoiceCancellable`, `invoiceReversable`), `HandleCancellation.php:38`, `InvoiceService::markDeleted` (`:241`).

## 8. Web UI (fork `pabloi/invoiceninja-ui`)

1. Settings > E-Invoice: add `CFE_UY` to the type options (`src/pages/settings/e-invoice/EInvoice.tsx:65`). Show xml-cfe connection status (read-only, from a new `GET /api/v1/einvoice/cfe_uy/health` endpoint).
2. Confirmation dialogs, UI only, for a CFE company and a draft invoice: before "Send email", "Mark sent", "Mark paid", bulk send and save-and-send, show "This emits a CFE (e-Ticket or e-Factura) to DGI. It cannot be deleted afterwards." with confirm and cancel. The API does not ask.
3. Invoice page: a CFE panel with status (not emitted, pending, emitted, rejected, failed), type, series and number, CAE, link to the DGI QR URL, and the failure message. The panel reads `backup.cfe_tipo`, `cfe_serie`, `cfe_numero`, `cfe_status`, `cfe_error`, `cfe_link_qr` and `guid` (CAE) from the invoice API response. No extra endpoint.
4. Invoice actions: "Retry CFE" when status is rejected or failed (calls P7). Hide Delete on emitted invoices. Cancel shows the phase 1 warning (section 7) and is hidden for non-admins.
5. Activity log: labels for activity types 900–903.
6. Saving the E-Invoice page must not reset `e_invoice_type`. The backend already preserves `CFE_UY` (`UpdateCompanyRequest.php:250`). Verify with the UI fork.

The UI ships inside the backend release (`public/`), so the backend build must bundle the forked UI.

## 9. Build and deploy

The fork is deployable anywhere stock Invoice Ninja is. No host-specific code. Deliverables:
- A release build that bundles `pabloi/invoiceninja-ui` into `public/`, published as a tagged GitHub release and a Docker image, matching upstream's packaging.
- Migration `cfe_logs` runs with the standard `php artisan migrate`.
- Environment: `CFE_UY_ENABLED`, `CFE_UY_BASE_URL`, `CFE_UY_TOKEN`, `CFE_UY_TIMEOUT`, `CFE_UY_VERIFY_SSL`.
- Upstream self-update must not replace the fork. Disable it (or point it at the fork's releases) and document a weekly check that the running version string carries the fork suffix.

Test instance: vps_domino, `test.domino.uy`, against the SICFE test environment. Production host is decided separately.

## 10. Cutover

1. Deploy the fork with `CFE_UY_ENABLED=false`. Smoke test against the SICFE test environment.
2. Set `e_invoice_type = CFE_UY`.
3. Turn off the xml-cfe `invoice-created` webhook in the same window, so no invoice gets two CFEs.
4. Send one real e-Ticket and one e-Factura. Verify with mcp-sicfe.

## 11. Tests

- Feature test per send path P1–P8: invitations primed, one job dispatched (with `Queue::fake`), no email before success, emails released after success.
- Double-dispatch test: P1 plus the event hook produces one xml-cfe call.
- Recurring test: auto-send for a CFE company sends no email before emission.
- Lock tests: edit and delete blocked on emitted invoices, cancel allowed for admins only.
- HtmlEngine test: variables empty before emission, filled after.

## 12. Open items

1. xml-cfe: implement `POST /v1/cfe/emit` and `GET /v1/cfe/status/{external_id}` (section 5a). Credit notes 102/112 later, for phase 2.
2. Invoices already sent before cutover, emitted by the xml-cfe webhook: backfill `backup.guid` and `cfe_logs` from xml-cfe so they are treated as emitted.
