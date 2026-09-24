<?php

namespace App\Solver;

/**
 * Tavlama benzetimi.
 *
 * Fikir şudur: sadece iyileştiren hamleleri kabul edersek ilk yerel
 * çukurda takılırız. Bu yüzden başta (sıcaklık yüksekken) kötüleştiren
 * hamleler de belirli bir olasılıkla kabul edilir; sıcaklık düştükçe bu
 * olasılık azalır ve arama yavaş yavaş kararlı hâle gelir.
 *
 * Kabul olasılığı: exp(-delta / sıcaklık)
 *   delta küçük (az kötüleşme)  → olasılık yüksek
 *   sıcaklık düşük              → olasılık düşük
 *
 * "En iyi" çizelge ayrıca saklanır: arama sonunda elde kalan çizelge,
 * görülen en iyisi olmak zorunda değildir.
 */
final class SimulatedAnnealing
{
    public function __construct(
        private readonly ProblemData $data,
        private readonly Penalty $penalty,
        private readonly HardConstraints $hard,
        private readonly MoveGenerator $moves,
    ) {}

    /**
     * @param  array{start_temp?:float,min_temp?:float,cooling?:float,max_iter?:int,seed?:int|null,progress_every?:int}  $params
     * @param  callable|null  $onProgress  fn(array $progress): void
     */
    public function run(Schedule $schedule, array $params = [], ?callable $onProgress = null, array $unplaced = []): SolveResult
    {
        $temp = (float) ($params['start_temp'] ?? 100);
        $minTemp = (float) ($params['min_temp'] ?? 0.01);
        $cooling = (float) ($params['cooling'] ?? 0.9995);
        $maxIter = (int) ($params['max_iter'] ?? 500000);
        $progressEvery = (int) ($params['progress_every'] ?? 2000);
        $seed = $params['seed'] ?? null;

        if ($seed !== null) {
            mt_srand((int) $seed);
        }

        $startedAt = microtime(true);

        $current = $this->penalty->total($schedule);
        $initial = $current;
        $best = $current;
        $bestSnapshot = $schedule->snapshot();

        $iter = 0;
        $accepted = 0;
        $rejectedByHard = 0;
        $randMax = mt_getrandmax();

        while ($temp > $minTemp && $iter < $maxIter) {
            $iter++;

            $move = $this->moves->random($schedule);

            if ($move === null) {
                break;
            }

            // Katı kuralı bozan hamle hiç denenmez — ve sıcaklığı da
            // düşürmez. Soğuma, gerçekten değerlendirilen hamlelerin
            // sayısını takip etmelidir; boşa üretilmiş örnekleri değil.
            if (! $this->hard->allows($schedule, $move)) {
                $rejectedByHard++;
            } else {
                $delta = $this->penalty->delta($schedule, $move);

                // delta < 0  → her zaman kabul (iyileşme)
                // delta >= 0 → sıcaklığa bağlı olasılıkla kabul
                if ($delta < 0 || exp(-$delta / $temp) > mt_rand() / $randMax) {
                    $move->applyTo($schedule);
                    $current += $delta;
                    $accepted++;

                    if ($current < $best) {
                        $best = $current;
                        $bestSnapshot = $schedule->snapshot();
                    }
                }

                $temp *= $cooling;
            }

            // İlerleme, hamlenin kabul edilip edilmediğinden bağımsız
            // olarak bildirilir: kullanıcı ilerleme çubuğunun durmasını
            // "takıldı" diye okur.
            if ($onProgress !== null && $iter % $progressEvery === 0) {
                $onProgress([
                    'iter' => $iter,
                    'max_iter' => $maxIter,
                    'penalty' => $current,
                    'best' => $best,
                    'temp' => round($temp, 4),
                    'accepted' => $accepted,
                ]);
            }
        }

        $schedule->restore($bestSnapshot);

        return new SolveResult(
            schedule: $schedule,
            initialPenalty: $initial,
            bestPenalty: $best,
            iterations: $iter,
            acceptedMoves: $accepted,
            rejectedByHard: $rejectedByHard,
            elapsedSeconds: microtime(true) - $startedAt,
            unplaced: $unplaced,
        );
    }
}
