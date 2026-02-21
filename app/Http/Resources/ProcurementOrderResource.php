<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'po_number'    => $this->po_number,
            'status'       => $this->status,
            'total_amount' => $this->total_amount,
            'expected_at'  => $this->expected_at?->toDateString(),
            'ordered_at'   => $this->ordered_at?->toISOString(),
            'received_at'  => $this->received_at?->toISOString(),
            'notes'        => $this->notes,
            'created_at'   => $this->created_at->toISOString(),
            'vendor'       => $this->whenLoaded('vendor', fn() => [
                'id'   => $this->vendor->id,
                'name' => $this->vendor->name,
                'code' => $this->vendor->code,
            ]),
            'request'      => $this->whenLoaded('request', fn() => [
                'id'             => $this->request->id,
                'request_number' => $this->request->request_number,
                'title'          => $this->request->title,
            ]),
            'items'        => $this->whenLoaded('items', fn() =>
                $this->items->map(fn($i) => [
                    'id'          => $i->id,
                    'item_name'   => $i->item_name,
                    'quantity'    => $i->quantity,
                    'unit_price'  => $i->unit_price,
                    'total_price' => $i->total_price,
                    'received_qty'=> $i->received_qty,
                ])
            ),
        ];
    }
}
