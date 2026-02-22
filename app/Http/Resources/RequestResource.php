<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'request_number' => $this->request_number,
            'title'          => $this->title,
            'description'    => $this->description,
            'status'         => $this->status,
            'priority'       => $this->priority,
            'required_date'  => $this->required_date?->toDateString(),
            'version'        => $this->version,
            'submitted_at'   => $this->submitted_at?->toISOString(),
            'completed_at'   => $this->completed_at?->toISOString(),
            'created_at'     => $this->created_at?->toISOString(),

            // Related (loaded conditionally)
            'requester'      => $this->whenLoaded('requester', fn() => [
                'id'            => $this->requester->id,
                'name'          => $this->requester->name,
                'employee_code' => $this->requester->employee_code ?? null,
            ]),
            'department'     => $this->whenLoaded('department', fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ]),
            'items'          => $this->whenLoaded('items', fn() =>
                $this->items->map(fn($item) => [
                    'id'              => $item->id,
                    'item_name'       => $item->item_name,
                    'category'        => $item->category,
                    'quantity'        => $item->quantity,
                    'unit'            => $item->unit,
                    'estimated_price' => $item->estimated_price,
                    'stock_id'        => $item->stock_id,
                ])
            ),
            'item_count'     => $this->whenCounted('items'),
            'approvals'      => $this->whenLoaded('approvals', fn() =>
                $this->approvals->map(fn($a) => [
                    'step'       => $a->step,
                    'action'     => $a->action,
                    'approver'   => $a->approver?->only(['id', 'name', 'role']),
                    'notes'      => $a->notes,
                    'acted_at'   => $a->acted_at?->toISOString(),
                ])
            ),
        ];
    }
}
