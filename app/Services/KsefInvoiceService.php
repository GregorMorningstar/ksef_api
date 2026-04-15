<?php

namespace App\Services;

use App\Models\KsefInvoice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class KsefInvoiceService
{
    public function __construct(private readonly KsefService $ksef) {}

    public function paginateForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 20);
        $page = isset($filters['page']) ? (int) $filters['page'] : null;
        $query = KsefInvoice::query()
            ->where('user_id', $user->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        if (!empty($filters['dateFrom'])) {
            $query->whereDate('issue_date', '>=', $filters['dateFrom']);
        }

        if (!empty($filters['dateTo'])) {
            $query->whereDate('issue_date', '<=', $filters['dateTo']);
        }

        if (!empty($filters['kind'])) {
            $kind = (string) $filters['kind'];
            $query->where(function ($q) use ($kind) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.invoiceType')) = ?", [$kind])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.formType')) = ?", [$kind]);
            });
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('ksef_id', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();
    }

    public function syncFromKsefForUser(
        User $user,
        array $filters,
        string $type = 'online',
        int $pageOffset = 0,
        int $pageSize = 50,
    ): array {
        $result = $this->ksef->searchInvoices($filters, $pageOffset, $pageSize, 'Desc', $type);
        $invoices = is_array($result['invoices'] ?? null) ? $result['invoices'] : [];

        $saved = 0;
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }

            $ksefId = $invoice['ksefReferenceNumber'] ?? $invoice['ksefNumber'] ?? null;
            if (!$ksefId) {
                continue;
            }

            KsefInvoice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'ksef_id' => $ksefId,
                ],
                $this->mapInvoicePayload($invoice)
            );

            $saved++;
        }

        return [
            'saved' => $saved,
            'fetched' => count($invoices),
            'hasMore' => (bool) ($result['hasMore'] ?? false),
            'isTruncated' => (bool) ($result['isTruncated'] ?? false),
        ];
    }

    public function syncNewInvoicesForUser(
        User $user,
        string $type = 'online',
        int $pageSize = 100,
    ): array {
        $lastInvoice = KsefInvoice::query()
            ->where('user_id', $user->id)
            ->orderByDesc('invoicing_date')
            ->first();

        $dateFrom = $lastInvoice && $lastInvoice->invoicing_date
            ? $lastInvoice->invoicing_date->format('Y-m-d')
            : now()->subYear()->format('Y-m-d');

        $dateTo = now()->format('Y-m-d');

        $filters = [
            'subjectType' => 'Subject1',
            'dateRange' => [
                'dateType' => 'Invoicing',
                'from' => $dateFrom . 'T00:00:00+00:00',
                'to' => $dateTo . 'T23:59:59+00:00',
            ],
        ];

        return $this->syncFromKsefForUser($user, $filters, $type, 0, $pageSize);
    }

    private function mapInvoicePayload(array $invoice): array
    {
        $seller = is_array($invoice['seller'] ?? null) ? $invoice['seller'] : [];
        $buyer = is_array($invoice['buyer'] ?? null) ? $invoice['buyer'] : [];
        $buyerIdentifier = is_array($buyer['identifier'] ?? null) ? $buyer['identifier'] : [];
        $formCode = is_array($invoice['formCode'] ?? null) ? $invoice['formCode'] : [];

        return [
            'reference_number' => $invoice['referenceNumber'] ?? $invoice['referenceNumberDefinition'] ?? null,
            'number' => $invoice['invoiceNumber'] ?? null,
            'issue_date' => $this->toDate($invoice['invoiceIssueDate'] ?? $invoice['issueDate'] ?? null),
            'invoicing_date' => $this->toDateTime($invoice['invoicingDate'] ?? null),
            'acquisition_date' => $this->toDateTime($invoice['acquisitionDate'] ?? null),
            'permanent_storage_date' => $this->toDateTime($invoice['permanentStorageDate'] ?? null),
            'sale_date' => $this->toDate($invoice['invoiceSaleDate'] ?? null),
            'due_date' => $this->toDate($invoice['paymentDueDate'] ?? null),
            'payment_date' => $this->toDate($invoice['paymentDate'] ?? null),
            'expected_payment_date' => $this->toDate($invoice['expectedPaymentDate'] ?? null),
            'buyer_nip' => $invoice['buyerNip'] ?? $buyerIdentifier['value'] ?? null,
            'buyer_name' => $invoice['buyerName'] ?? $buyer['name'] ?? null,
            'buyer_address' => $invoice['buyerAddress'] ?? null,
            'buyer_identifier_type' => $buyerIdentifier['type'] ?? null,
            'buyer_identifier_value' => $buyerIdentifier['value'] ?? null,
            'seller_nip' => $invoice['sellerNip'] ?? $seller['nip'] ?? null,
            'seller_name' => $invoice['sellerName'] ?? $seller['name'] ?? null,
            'seller_address' => $invoice['sellerAddress'] ?? null,
            'total_gross' => $invoice['totalGrossAmount'] ?? $invoice['grossAmount'] ?? null,
            'total_net' => $invoice['totalNetAmount'] ?? $invoice['netAmount'] ?? null,
            'total_vat' => $invoice['totalVatAmount'] ?? $invoice['vatAmount'] ?? null,
            'currency' => $invoice['currency'] ?? null,
            'invoicing_mode' => $invoice['invoicingMode'] ?? null,
            'invoice_type' => $invoice['invoiceType'] ?? null,
            'form_code_system_code' => $formCode['systemCode'] ?? null,
            'form_code_schema_version' => $formCode['schemaVersion'] ?? null,
            'form_code_value' => $formCode['value'] ?? null,
            'is_self_invoicing' => (bool) ($invoice['isSelfInvoicing'] ?? false),
            'has_attachment' => (bool) ($invoice['hasAttachment'] ?? false),
            'invoice_hash' => $invoice['invoiceHash'] ?? null,
            'status' => 'new',
            'raw_json' => $invoice,
        ];
    }

    private function toDateTime(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
