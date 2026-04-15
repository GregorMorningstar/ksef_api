<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KsefMyInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
            'kind' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:190'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
