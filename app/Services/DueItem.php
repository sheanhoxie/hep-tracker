<?php

namespace App\Services;

use App\Models\Prescription;

final readonly class DueItem
{
    public function __construct(
        public Prescription $prescription,
        public string $cadence, // daily | weekly
        public ?int $remainingThisWeek,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prescription_id' => $this->prescription->id,
            'exercise_slug' => $this->prescription->exercise->slug,
            'exercise_name' => $this->prescription->exercise->canonical_name,
            'thumb' => $this->prescription->exercise->media->firstWhere('kind', 'image')?->url,
            'dosage' => $this->prescription->dosageSummary(),
            'cadence' => $this->cadence,
            'remaining_this_week' => $this->remainingThisWeek,
            'notes' => $this->prescription->notes,
        ];
    }
}
