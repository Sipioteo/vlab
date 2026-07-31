<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Closure;
use App\Support\Dates;

final class ClosureResource
{
    /** @return array<string,mixed> */
    public static function toArray(Closure $closure): array
    {
        return [
            'id' => (int) $closure->id,
            'title' => $closure->title,
            'description' => $closure->description,
            'start_date' => Dates::datePart($closure->start_date),
            'end_date' => Dates::datePart($closure->end_date),
            'blocks_pickup' => (bool) $closure->blocks_pickup,
            'blocks_return' => (bool) $closure->blocks_return,
            'is_recurring_yearly' => (bool) $closure->is_recurring_yearly,
            'created_at' => Dates::iso($closure->created_at),
        ];
    }
}
