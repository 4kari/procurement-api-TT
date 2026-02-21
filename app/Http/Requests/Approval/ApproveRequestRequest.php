<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRequestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'notes'   => ['nullable', 'string', 'max:500'],
            'version' => ['required', 'integer'],
        ];
    }
}
