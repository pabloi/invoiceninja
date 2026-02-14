# CFE_UY Operations Runbook

## 1. Overview

CFE_UY integrates Invoice Ninja with Uruguay's Comprobantes Fiscales Electronicos (CFE) system via an external `xml-cfe` service. Invoice Ninja handles workflow orchestration (queueing, idempotency, email gating, logging), while `xml-cfe` handles XML generation, SICFE submission, and signature.

## 2. Environment Configuration

### Required Environment Variables

```env
CFE_UY_ENABLED=true          # Feature flag - must be true to enable
CFE_UY_BASE_URL=https://your-xml-cfe-service.example.com
CFE_UY_TOKEN=your-bearer-token
CFE_UY_TIMEOUT=30            # HTTP timeout in seconds
CFE_UY_VERIFY_SSL=true       # Set to false only in development
```

### Company Settings

Set `e_invoice_type` to `CFE_UY` via the API:

```
PUT /api/v1/companies/{id}
{
  "settings": {
    "e_invoice_type": "CFE_UY"
  }
}
```

**Warning**: On hosted platform, once set to `CFE_UY`, the type is locked and cannot be changed.

### Tax Map Configuration

Default tax mapping in `config/services.php`:

| UY Tax Rate | IndFact Value | Description |
|-------------|---------------|-------------|
| 22%         | 3             | IVA Basica  |
| 10%         | 2             | IVA Minima  |
| 0% (named)  | 1             | Exento      |
| 0% (no tax) | 6             | No facturable |

Override via environment if needed (requires code change for custom rates).

## 3. Queue and Worker Requirements

### Queue Configuration

The `SendToCfeUy` job runs on the default queue with:
- **Max retries**: 5
- **Backoff**: 5s, 30s, 240s, 3600s, 7200s (exponential)
- **Overlap lock**: `send_to_cfe_uy_{company_key}_{invoice_id}` (60s release/expire)

### Worker Command

```bash
php artisan queue:work --queue=default --tries=5 --timeout=120
```

Ensure at least one queue worker is running for CFE emissions to process.

## 4. Operational Flows

### 4.1 Normal Emission Flow

1. User sends invoice (via UI or API with `send_email=true`)
2. Invitations marked as `primed` (email deferred)
3. `SendToCfeUy` job dispatched to queue
4. Job maps invoice to CFE payload via `Mapper`
5. Job calls `xml-cfe` service via `Client::emit()`
6. `ResponseProcessor` normalizes the response
7. `CfeLog` entry created with full request/response
8. On success:
   - `backup->guid` set (idempotency marker)
   - Activity log: `CFE_UY_INVOICE_SENT` (158)
   - Primed invitations released → email sent
9. On failure:
   - Activity log: `CFE_UY_INVOICE_SENT_FAILURE` (159)
   - Job retries per backoff schedule

### 4.2 Retry Flow

Send `retry_e_send=true` on the invoice update endpoint:

```
PUT /api/v1/invoices/{id}
{
  "retry_e_send": true
}
```

Only retries if `backup->guid` is empty (not yet emitted).

### 4.3 Validation Flow

Pre-flight validation before emission:

```
POST /api/v1/einvoice/validateEntity
{
  "entity": "invoices",
  "entity_id": "{hashed_id}"
}
```

Returns 200 with `passes: true` or 422 with structured field-level errors.

## 5. Observability

### Activity Log Constants

| Constant | ID | Meaning |
|----------|-----|---------|
| `CFE_UY_INVOICE_SENT` | 158 | Successful CFE emission |
| `CFE_UY_INVOICE_SENT_FAILURE` | 159 | Failed CFE emission |
| `CFE_UY_STATUS_CHECKED` | 160 | Status query success |
| `CFE_UY_STATUS_CHECK_FAILURE` | 161 | Status query failure |

### SystemLog Constants

| Constant | Value | Type |
|----------|-------|------|
| `CATEGORY_CFE_UY` | 9 | Category |
| `EVENT_CFE_UY_SUCCESS` | 75 | Event |
| `EVENT_CFE_UY_FAILURE` | 74 | Event |
| `TYPE_CFE_UY_SEND` | 1200 | Type |
| `TYPE_CFE_UY_STATUS` | 1201 | Type |

### Database: cfe_logs Table

Query recent emissions:

```sql
SELECT id, invoice_id, provider_status, provider_code, cfe_tipo, cfe_serie,
       cfe_numero, cae_id, trace_id, created_at
FROM cfe_logs
WHERE company_id = ?
ORDER BY id DESC
LIMIT 20;
```

### Key Fields to Monitor

- `provider_status`: `EN` = success, `PE` = pending, `RE` = rejected, `ERROR` = failure
- `provider_code`: 0 = success, non-zero = error code from SICFE
- `trace_id`: Correlates with xml-cfe service logs

## 6. Incident Playbooks

### 6.1 All Emissions Failing

**Symptoms**: All invoices stuck without `backup->guid`, CfeLog shows ERROR status.

**Steps**:
1. Check `CFE_UY_ENABLED` is `true`
2. Check `CFE_UY_BASE_URL` and `CFE_UY_TOKEN` are correct
3. Test connectivity: `curl -H "Authorization: Bearer $TOKEN" $BASE_URL/v1/cfe/status/test`
4. Check queue workers are running: `php artisan queue:monitor`
5. Check `cfe_logs` for `provider_code` and `provider_description`

### 6.2 Intermittent Failures

**Symptoms**: Some emissions succeed, some fail with HTTP 5xx.

**Steps**:
1. Check `cfe_logs` for `trace_id` patterns
2. Review xml-cfe service health and load
3. Failures with `retryable=true` will auto-retry (5 attempts)
4. Manually retry via API: `PUT /api/v1/invoices/{id} {"retry_e_send": true}`

### 6.3 Timeout After Possible Submission

**Symptoms**: HTTP timeout, unknown if SICFE received the document.

**Steps**:
1. Check `cfe_logs` for the invoice - look for prior successful entry
2. If `cae_id` exists in a prior log, the emission succeeded (timeout was on response)
3. The idempotency key prevents duplicate submissions on retry
4. If no prior log exists, retry is safe

### 6.4 Emails Not Sending After CFE

**Symptoms**: CFE emissions succeed but customer emails are stuck.

**Steps**:
1. Check `invitations` table: `email_error` should be empty (not `primed`)
2. If still `primed`, the success handler didn't release them
3. Check Activity log for `CFE_UY_INVOICE_SENT` (158) entry
4. Manual fix: clear `email_error` on invitations and re-send

### 6.5 Tax Map Mismatch

**Symptoms**: Emissions fail with "Unmapped tax rate" in logs.

**Steps**:
1. Check `provider_description` in `cfe_logs` for the specific rate
2. Add the rate to `services.cfe_uy.tax_map` in config
3. Re-validate: `POST /api/v1/einvoice/validateEntity`

## 7. Rollout Checklist

### Phase 1: Feature Flag Off (Default)
- [x] Deploy code with `CFE_UY_ENABLED=false`
- [x] Verify app boots, no constant errors
- [x] Run existing test suite - all green
- [x] Verify PEPPOL/Verifactu flows unaffected

### Phase 2: Staging Validation
- [ ] Set `CFE_UY_ENABLED=true` on staging
- [ ] Configure `CFE_UY_BASE_URL` and `CFE_UY_TOKEN`
- [ ] Create test company with `e_invoice_type=CFE_UY`
- [ ] Test successful emission
- [ ] Test timeout handling (set `CFE_UY_TIMEOUT=1`)
- [ ] Test retry behavior
- [ ] Test email gating (email sent only after success)
- [ ] Test `download_e_invoice` returns signed XML

### Phase 3: Limited Production
- [ ] Enable for one production company
- [ ] Monitor `cfe_logs` and `system_logs` for 48 hours
- [ ] Verify queue failure rate < 1%
- [ ] Confirm email delivery after CFE success

### Phase 4: Broad Rollout
- [ ] Enable for all Uruguay companies
- [ ] Monitor for 1-2 weeks
- [ ] Document any new tax map entries needed

### Rollback Criteria
- Queue failure rate > 10% sustained
- SICFE returning systematic errors (provider_code non-zero)
- Email delivery blocked for > 1 hour
- **Rollback**: Set `CFE_UY_ENABLED=false` - invoices will send email directly without CFE

## 8. Local Development and Testing

### Mock Service Setup

Tests use `Http::fake()` - no external service needed:

```php
Http::fake([
    'cfe-test.example.com/v1/cfe/emit' => Http::response([
        'success' => true,
        'status' => 'EN',
        'cfe' => ['tipo' => 101, 'serie' => 'A', 'numero' => 1],
        'provider' => ['codigo' => 0, 'descripcion' => 'OK'],
        'trace_id' => 'test-trace',
    ], 200),
]);
```

### Running CFE_UY Tests

```bash
php artisan test --filter=CfeUy
```

This runs both unit tests (`tests/Unit/CfeUy/`) and feature tests (`tests/Feature/EInvoice/CfeUy/`).

## 9. Known Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Tax/document mapping mismatches | Strict validator + explicit errors on unmapped rates |
| Timeout after SICFE accepted document | Idempotency key + status query before blind retry |
| Large XML/base64 payload growth | Optional storage truncation policy in phase 2 |
| Drift with xml-cfe API contract | Versioned endpoint (/v1) and shared contract tests |
| Queue worker down | Monitor queue depth; alerts on job failure rate |
