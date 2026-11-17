<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\VisitSchedule
 */
class VisitScheduleResource extends JsonResource
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
            'user_name' => $this->user?->name,
            'store_id' => $this->store_id,
            'store_name' => $this->store?->name,
            'store_address' => $this->store?->address,
            'store_latitude' => $this->store?->latitude,
            'store_longitude' => $this->store?->longitude,
            'store_phone' => $this->store?->phone,
            'scheduled_date' => $this->scheduled_date->format('Y-m-d'),
            'sequence' => $this->sequence,
            'status' => $this->status,
            'is_completed' => $this->status === 'completed',
            'notes' => $this->notes,
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
