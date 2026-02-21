<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'                     => ['required', 'string', 'max:200'],
            'description'               => ['nullable', 'string'],
            'notes'                     => ['nullable', 'string'],
            'priority'                  => ['nullable', 'integer', 'between:1,5'],
            'required_date'             => ['nullable', 'date', 'after_or_equal:today'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.item_name'         => ['required', 'string', 'max:200'],
            'items.*.category'          => ['required', 'string', 'in:OFFICE_SUPPLY,ELECTRONIC,FURNITURE,SERVICE,RAW_MATERIAL,OTHER'],
            'items.*.quantity'          => ['required', 'numeric', 'gt:0'],
            'items.*.unit'              => ['required', 'string', 'max:30'],
            'items.*.estimated_price'   => ['nullable', 'numeric', 'min:0'],
            'items.*.stock_id'          => ['nullable', 'uuid', 'exists:stock,id'],
            'items.*.notes'             => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'         => 'Minimal satu item harus diisi.',
            'items.min'              => 'Minimal satu item harus diisi.',
            'items.*.quantity.gt'    => 'Jumlah item harus lebih dari 0.',
            'required_date.after_or_equal' => 'Tanggal kebutuhan tidak boleh di masa lalu.',
        ];
    }
}
