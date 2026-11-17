<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestVisit = $this->relationLoaded('latestVisit') ? $this->latestVisit : null;
        $user = $request->user();

        $visitedLabel = null;
        $lastVisitData = null;
        $isCheckedInToday = false;

        if ($latestVisit) {
            $salesName = $latestVisit->relationLoaded('user') && $latestVisit->user
                ? $latestVisit->user->name
                : 'Sales';

            $formattedTime = $latestVisit->visited_at
                ? $latestVisit->visited_at->translatedFormat('d M Y, H:i')
                : '';

            $visitedLabel = "Toko telah dikunjungi oleh {$salesName} pada {$formattedTime}";

            $lastVisitData = [
                'id' => $latestVisit->id,
                'sales_name' => $salesName,
                'visited_at' => $latestVisit->visited_at?->toIso8601String(),
                'photo_url' => $latestVisit->photo_url,
                'notes' => $latestVisit->notes,
            ];

            if ($latestVisit->visited_at && $latestVisit->visited_at->isToday()) {
                if ($user && $user->isSales()) {
                    $isCheckedInToday = ($latestVisit->user_id === $user->id);
                } else {
                    $isCheckedInToday = true;
                }
            }
        }

        $canPos = ($user && $user->isAdmin()) ? true : $isCheckedInToday;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_name' => $this->owner_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'notes' => $this->notes,
            'assigned_sales_id' => $this->assigned_sales_id,
            'assigned_sales_name' => $this->assignedSales?->name,
            'schedule_frequency' => $this->schedule_frequency,
            'is_active' => (bool) $this->is_active,
            'visited_label' => $visitedLabel,
            'is_visited' => $latestVisit !== null,
            'is_checked_in_today' => $isCheckedInToday,
            'can_pos' => $canPos,
            'last_visit' => $lastVisitData,
            'photo_url'  => $this->photo_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
