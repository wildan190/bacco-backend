<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreVisitResource extends JsonResource
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
            'user_id' => $this->user_id,
            'sales_name' => $this->user?->name,
            'sales_phone' => $this->user?->phone,
            'store_id' => $this->store_id,
            'store_name' => $this->store?->name,
            'store_address' => $this->store?->address,
            'store' => new StoreResource($this->whenLoaded('store')),
            'visited_at' => $this->visited_at?->toIso8601String(),
            'visited_at_formatted' => $this->visited_at ? $this->visited_at->format('d M Y, H:i') : null,
            'photo_url' => $this->photo_url,
            'photo_path' => $this->photo_path,
            'notes' => $this->notes,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
