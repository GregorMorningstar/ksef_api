<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KsefInvoice extends Model
{
    protected $fillable = [
        'user_id',
        'ksef_id',
        'reference_number',
        'number',
        'issue_date',
        'invoicing_date',
        'acquisition_date',
        'permanent_storage_date',
        'sale_date',
        'due_date',
        'payment_date',
        'expected_payment_date',
        'payment_status',
        'buyer_nip',
        'buyer_name',
        'buyer_address',
        'buyer_identifier_type',
        'buyer_identifier_value',
        'seller_nip',
        'seller_name',
        'seller_address',
        'total_gross',
        'total_net',
        'total_vat',
        'currency',
        'invoicing_mode',
        'invoice_type',
        'form_code_system_code',
        'form_code_schema_version',
        'form_code_value',
        'is_self_invoicing',
        'has_attachment',
        'invoice_hash',
        'status',
        'raw_json',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'invoicing_date' => 'datetime',
        'acquisition_date' => 'datetime',
        'permanent_storage_date' => 'datetime',
        'sale_date' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
        'expected_payment_date' => 'date',
        'is_self_invoicing' => 'boolean',
        'has_attachment' => 'boolean',
        'raw_json' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $invoice): void {
            if (!$invoice->expected_payment_date && $invoice->sale_date) {
                $invoice->expected_payment_date = date('Y-m-d', strtotime($invoice->sale_date . ' +14 days'));
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Możliwość zmiany statusu płatności i daty płatności
    public function markAsPaid($date = null)
    {
        $this->payment_status = 'zapłacona';
        $this->payment_date = $date ?? now();
        $this->save();
    }

    public function markAsUnpaid()
    {
        $this->payment_status = 'nie zapłacona';
        $this->payment_date = null;
        $this->save();
    }

    public function markAsPending()
    {
        $this->payment_status = 'oczekuje';
        $this->payment_date = null;
        $this->save();
    }
}



