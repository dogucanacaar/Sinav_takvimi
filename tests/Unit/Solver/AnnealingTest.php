<?php

namespace Tests\Unit\Solver;

use App\Solver\HardConstraintChecker;
use App\Solver\HardConstraints;
use App\Solver\InitialBuilder;
use App\Solver\MoveGenerator;
use App\Solver\Penalty;
use App\Solver\SimulatedAnnealing;
use App\Solver\SolveResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProblemFactory;

/**
 * Tavlama benzetiminin iki temel güvencesi:
 *   1. Ceza puanını düşürür (yoksa motorun varlık sebebi yok).
 *   2. Katı kuralı hiçbir zaman bozmaz.
 * Ayrıca sabit tohumla aynı sonucu vermelidir; yoksa iki çözümü
 * karşılaştırmanın anlamı kalmaz.
 */
final class AnnealingTest extends TestCase
{
    private function solve(int $seed, array $override = []): SolveResult
    {
        $data = ProblemFactory::random(seed: 11);
        $penalty = new Penalty($data);
        $hard = new HardConstraints($data);

        $built = (new InitialBuilder($data))->build();

        $engine = new SimulatedAnnealing($data, $penalty, $hard, new MoveGenerator($data));

        return $engine->run($built['schedule'], array_merge([
            'start_temp' => 100,
            'min_temp' => 0.01,
            'cooling' => 0.999,
            'max_iter' => 20000,
            'seed' => $seed,
        ], $override), null, $built['unplaced']);
    }

    public function test_ceza_puani_duser(): void
    {
        $result = $this->solve(2026);

        $this->assertLessThan(
            $result->initialPenalty,
            $result->bestPenalty,
            'Tavlama benzetimi başlangıç çözümünü iyileştirmeliydi'
        );
    }

    public function test_sabit_tohum_ayni_sonucu_uretir(): void
    {
        $first = $this->solve(2026);
        $second = $this->solve(2026);

        $this->assertSame($first->bestPenalty, $second->bestPenalty);
        $this->assertSame($first->iterations, $second->iterations);
        $this->assertEquals($first->schedule->snapshot(), $second->schedule->snapshot());
    }

    public function test_farkli_tohum_farkli_arama_yolu_izler(): void
    {
        $first = $this->solve(1);
        $second = $this->solve(2);

        $this->assertNotEquals(
            $first->schedule->snapshot(),
            $second->schedule->snapshot(),
            'Farklı tohum farklı bir arama yolu izlemeli'
        );
    }

    public function test_sonuc_cizelgesi_kati_kural_bozmaz(): void
    {
        $data = ProblemFactory::random(seed: 11);
        $result = $this->solve(777);

        $violations = (new HardConstraintChecker)->check($result->schedule, $data, requireAll: false);

        $this->assertSame([], array_map(strval(...), $violations));
    }

    public function test_bildirilen_en_iyi_puan_gercekten_cizelgenin_puanidir(): void
    {
        $data = ProblemFactory::random(seed: 11);
        $result = $this->solve(4242);

        $penalty = new Penalty($data);

        $this->assertSame(
            $penalty->total($result->schedule),
            $result->bestPenalty,
            'Dönen çizelge, bildirilen en iyi puana ait olmalı'
        );
    }

    public function test_ilerleme_geri_cagrisi_calisir(): void
    {
        $seen = [];

        $data = ProblemFactory::random(seed: 11);
        $built = (new InitialBuilder($data))->build();

        $engine = new SimulatedAnnealing(
            $data,
            new Penalty($data),
            new HardConstraints($data),
            new MoveGenerator($data),
        );

        $engine->run($built['schedule'], [
            'max_iter' => 5000,
            'cooling' => 0.9999,
            'progress_every' => 1000,
            'seed' => 5,
        ], function (array $progress) use (&$seen): void {
            $seen[] = $progress;
        });

        $this->assertNotEmpty($seen, 'İlerleme geri çağrısı en az bir kez çalışmalıydı');
        $this->assertArrayHasKey('penalty', $seen[0]);
        $this->assertArrayHasKey('iter', $seen[0]);
    }
}
