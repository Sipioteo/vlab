<?php

declare(strict_types=1);

use App\Models\Closure;

/**
 * Seeds the two closures of SPEC §15.5. Idempotent by title.
 */
final class ClosuresSeeder
{
    public function run(?callable $out = null): void
    {
        $year = (int) date('Y');
        $created = 0;
        if (Closure::where('title', 'Chiusura estiva')->first() === null) {
            Closure::create([
                'title' => 'Chiusura estiva',
                'description' => 'Il laboratorio resta chiuso per la pausa estiva.',
                'start_date' => sprintf('%d-08-08', $year),
                'end_date' => sprintf('%d-08-23', $year),
                'blocks_pickup' => true,
                'blocks_return' => true,
                'is_recurring_yearly' => false,
            ]);
            $created++;
        }
        if (Closure::where('title', 'Festività natalizie')->first() === null) {
            Closure::create([
                'title' => 'Festività natalizie',
                'description' => 'Chiusura per le festività natalizie.',
                'start_date' => sprintf('%d-12-24', $year),
                'end_date' => sprintf('%d-01-06', $year + 1),
                'blocks_pickup' => true,
                'blocks_return' => true,
                'is_recurring_yearly' => true,
            ]);
            $created++;
        }
        if ($out !== null) {
            $out("Chiusure: {$created} nuove (2 totali).");
        }
    }
}
