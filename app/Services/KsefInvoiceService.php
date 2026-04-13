<?php

namespace App\Services;

use App\Models\KsefInvoice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class KsefInvoiceService
{
    public function paginateForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['perPage'] ?? 20);
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

        return $query->paginate($perPage)->withQueryString();
    }
}
