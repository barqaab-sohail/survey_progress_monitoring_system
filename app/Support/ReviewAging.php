<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ReviewAging
{
    public const DAYS = 7;

    public static function query(Builder $query, array $filters): Builder
    {
        return $query->when($filters['feeder_id'] ?? null, fn ($q, $id) => $q->where('feeder_id', $id))
            ->when(($filters['overdue'] ?? false), fn ($q) => $q->whereRaw('COALESCE(resubmitted_at, created_at) <= ?', [now()->subDays(self::DAYS)]));
    }

    public static function summary(Builder $query): array
    {
        $oldest = (clone $query)->orderByRaw('COALESCE(resubmitted_at, created_at)')->first();

        return [
            'pending' => (clone $query)->count(),
            'overdue' => self::query(clone $query, ['overdue' => true])->count(),
            'oldest_days' => $oldest ? self::days($oldest) : 0,
        ];
    }

    public static function days($item): int
    {
        return max(0, (int) ($item->resubmitted_at ?? $item->created_at)->diffInDays(now()));
    }
}
