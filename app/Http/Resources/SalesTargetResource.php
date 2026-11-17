<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesTargetResource extends JsonResource
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
            'period_month' => (int) $this->period_month,
            'period_year' => (int) $this->period_year,
            'target_revenue' => (float) $this->target_revenue,
            'target_visits' => (int) $this->target_visits,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
