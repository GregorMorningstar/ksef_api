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
        'sale_date',
        'due_date',
        'payment_date',
        'expected_payment_date',
        'payment_status',
        'buyer_nip',
        'buyer_name',
        'buyer_address',
        'seller_nip',
        'seller_name',
        'seller_address',
        'total_gross',
        'total_net',
        'total_vat',
        'currency',
        'status',
        'raw_json',
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



