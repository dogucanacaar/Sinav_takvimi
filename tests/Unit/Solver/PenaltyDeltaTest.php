<?php

namespace Tests\Unit\Solver;

use App\Solver\HardConstraints;
use App\Solver\InitialBuilder;
use App\Solver\MoveGenerator;
use App\Solver\Penalty;
use App\Solver\Schedule;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProblemFactory;

/**
 * Projedeki en önemli test.
 *
 * Artımlı ceza hesabı (delta) yanlışsa motor yanlış yöne optimize eder ve
 * bu hata kolay fark edilmez: program üretilir, sayı düşer, ama düşen sayı
 * gerçeği göstermez. Bu yüzden delta() her hamlede total() farkına birebir
 * eşit olmalıdır — yaklaşık değil, birebir.
 */
final class PenaltyDeltaTest extends TestCase
{
    public function test_delta_total_farkina_birebir_esittir(): void
    {
        $data = ProblemFactory::random(seed: 42);
        $penalty = new Penalty($data);
        $hard = new HardConstraints($data);
        $moves = new MoveGenerator($data);

        $schedule = (new InitialBuilder($data))->build()['schedule'];

        mt_srand(1234);

        $checked = 0;

        for ($i = 0; $i < 3000 && $checked < 500; $i++) {
            $move = $moves->random($schedule);

            if ($move === null || ! $hard->allows($schedule, $move)) {
                continue;
            }

            $before = $penalty->total($schedule);
            $delta = $penalty->delta($schedule, $move);

            $move->applyTo($schedule);
            $after = $penalty->total($schedule);

            $this->assertSame(
                $after - $before,
                $delta,
                "Hamle #{$i}: delta() ile total() farkı uyuşmuyor"
            );

            $checked++;
        }

        $this->assertGreaterThan(100, $checked, 'Anlamlı sayıda hamle denenmeliydi');
    }

    public function test_delta_cizelgeyi_degistirmez(): void
    {
        $data = ProblemFactory::random(seed: 9);
        $penalty = new Penalty($data);
        $hard = new HardConstraints($data);
        $moves = new MoveGenerator($data);

        $schedule = (new InitialBuilder($data))->build()['schedule'];

        mt_srand(555);

        $snapshot = $schedule->snapshot();

        for ($i = 0; $i < 300; $i++) {
            $move = $moves->random($schedule);

            if ($move === null || ! $hard->allows($schedule, $move)) {
                continue;
            }

            $penalty->delta($schedule, $move);
        }

        $this->assertEquals($snapshot, $schedule->snapshot(), 'delta() çizelgeyi bozmamalı');
    }

    public function test_agirliklar_ceza_puanini_dogrudan_etkiler(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        // 101 numaralı öğrenci aynı gün art arda iki sınava giriyor: E1 + E2
        $schedule->assign(1, 1, 1);
        $schedule->assign(2, 2, 1);

        $onlyE1 = new Penalty($data, ['E1' => 10, 'E2' => 0, 'E3' => 0, 'E4' => 0]);
        $onlyE2 = new Penalty($data, ['E1' => 0, 'E2' => 25, 'E3' => 0, 'E4' => 0]);

        $this->assertSame(10, $onlyE1->total($schedule));
        $this->assertSame(25, $onlyE2->total($schedule));
    }

    public function test_farkli_binalar_e3_uretir(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1); // bina 1
        $schedule->assign(2, 3, 3); // bina 2, aynı gün, art arda değil

        $penalty = new Penalty($data, ['E1' => 0, 'E2' => 0, 'E3' => 15, 'E4' => 0]);

        $this->assertSame(15, $penalty->total($schedule));
    }

    public function test_e4_bos_kapasiteyi_cezalandirir(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(4, 1, 4); // 1 öğrenci, 100 kişilik derslik → 99 israf

        $penalty = new Penalty($data, ['E1' => 0, 'E2' => 0, 'E3' => 0, 'E4' => 1]);

        $this->assertSame(99, $penalty->total($schedule));
    }

    public function test_dokum_toplami_total_ile_ayni(): void
    {
        $data = ProblemFactory::random(seed: 3);
        $penalty = new Penalty($data);
        $schedule = (new InitialBuilder($data))->build()['schedule'];

        $this->assertSame($penalty->total($schedule), $penalty->breakdown($schedule)['total']);
    }
}
