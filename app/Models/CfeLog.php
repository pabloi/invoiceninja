<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Models;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property string|null $external_invoice_id
 * @property string $direction
 * @property string|null $provider_status
 * @property int|null $provider_code
 * @property string|null $provider_description
 * @property int|null $cfe_tipo
 * @property string|null $cfe_serie
 * @property int|null $cfe_numero
 * @property string|null $cae_id
 * @property int|null $cae_dnro
 * @property int|null $cae_hnro
 * @property \Carbon\Carbon|null $cae_vto
 * @property string|null $hash
 * @property string|null $link_qr
 * @property string|null $imagen_qr_base64
 * @property string|null $xml_firmado
 * @property object|null $request_payload
 * @property object|null $response_payload
 * @property string|null $trace_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\Invoice $invoice
 */
class CfeLog extends Model
{
    public $timestamps = true;

    protected $casts = [
        'cae_vto' => 'date',
        'request_payload' => 'object',
        'response_payload' => 'object',
    ];

    protected $guarded = ['id'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
