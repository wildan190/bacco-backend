<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'user_id' => $this->user_id,
            'sales_name' => $this->user?->name,
            'store_id' => $this->store_id,
            'store_name' => $this->store?->name,
            'store_address' => $this->store?->address,
            'store' => new StoreResource($this->whenLoaded('store')),
            'total_amount' => (float) $this->total_amount,
            'cash_paid' => (float) $this->cash_paid,
            'change_due' => (float) $this->change_due,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'status' => $this->status,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
