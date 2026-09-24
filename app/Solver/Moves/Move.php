<?php

namespace App\Solver\Moves;

use App\Solver\Schedule;

/**
 * Bir hamle. İki şey yapabilmesi gerekir:
 *   - nereye gitmek istediğini söylemek (targets) — kural kontrolü bunun üstünden yapılır
 *   - uygulanmak ve geri alınmak — ceza farkı hesabı sırada uygula/geri al yapar
 */
interface Move
{
    /** @return int[] bu hamleden etkilenen sınav id'leri */
    public function affectedExams(): array;

    /** @return array<int,array{slot:int,room:int}> examId => hedef yer */
    public function targets(): array;

    public function applyTo(Schedule $schedule): void;

    /** Hamleyi uygulanmadan önceki hâline döndürür. */
    public function revert(Schedule $schedule): void;
}
