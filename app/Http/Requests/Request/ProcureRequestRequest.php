<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

class ProcureRequestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'vendor_id'               => ['required', 'uuid', 'exists:vendors,id'],
            'expected_at'             => ['nullable', 'date', 'after_or_equal:today'],
            'notes'                   => ['nullable', 'string'],
            'version'                 => ['required', 'integer'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.request_item_id' => ['nullable', 'uuid', 'exists:request_items,id'],
            'items.*.item_name'       => ['required', 'string', 'max:200'],
            'items.*.quantity'        => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check vendor is active
            if ($this->vendor_id) {
                $vendor = \App\Models\Vendor::find($this->vendor_id);
                if ($vendor && !$vendor->is_active) {
                    $validator->errors()->add('vendor_id', 'Vendor tidak aktif.');
                }
            }
        });
    }
}
