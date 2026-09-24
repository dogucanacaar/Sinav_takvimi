<?php

namespace Tests\Unit\Solver;

use App\Solver\Invigilation\InvigilationAssigner;
use App\Solver\Invigilation\InvigilationChecker;
use App\Solver\Invigilation\InvigilationResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvigilationFactory;

/**
 * Gözetmen atamasının iki güvencesi:
 *   1. Katı kuralı bozmaz (K4 ve "aynı saatte tek sınav").
 *   2. Yükü dengeler — ölçütü yüklerin standart sapmasıdır.
 */
final class InvigilationTest extends TestCase
{
    public function test_gereken_sayida_gozetmen_atanir(): void
    {
        // 40 öğrenciye bir gözetmen: 120 öğrenci → 3 gözetmen.
        $data = InvigilationFactory::make(10, [
            1 => ['slot' => 1, 'size' => 120],
            2 => ['slot' => 2, 'size' => 40],
            3 => ['slot' => 3, 'size' => 5],
        ]);

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertCount(3, $result->assignments[1]);
        $this->assertCount(1, $result->assignments[2]);
        $this->assertCount(1, $result->assignments[3], 'Küçük sınavda bile en az bir gözetmen olmalı');
        $this->assertFalse($result->hasShortage());
    }

    public function test_musait_olmayan_saate_atanmaz(): void
    {
        // 1 ve 2 numaralı hocalar 1. saatte müsait değil.
        $data = InvigilationFactory::make(
            3,
            [1 => ['slot' => 1, 'size' => 10]],
            unavailable: [1 => [1], 2 => [1]],
        );

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertSame([3], $result->assignments[1], 'Sadece müsait olan hoca atanmalı');
        $this->assertSame([], (new InvigilationChecker)->check($result->assignments, $data));
    }

    public function test_ayni_saatte_iki_sinava_atanmaz(): void
    {
        // Aynı saatte iki sınav, iki hoca: her birine bir sınav düşmeli.
        $data = InvigilationFactory::make(2, [
            1 => ['slot' => 1, 'size' => 10],
            2 => ['slot' => 1, 'size' => 10],
        ]);

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertNotEquals($result->assignments[1], $result->assignments[2]);
        $this->assertSame([], (new InvigilationChecker)->check($result->assignments, $data));
    }

    public function test_havuz_yetmezse_eksiklik_raporlanir_hata_firlatilmaz(): void
    {
        // 200 öğrenci → 5 gözetmen gerekli, ama sadece 2 hoca var.
        $data = InvigilationFactory::make(2, [
            1 => ['slot' => 1, 'size' => 200],
        ]);

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertTrue($result->hasShortage());
        $this->assertSame(3, $result->shortages[1]);
        $this->assertCount(2, $result->assignments[1], 'Bulunabilen hocalar yine de atanmalı');
    }

    public function test_gecmis_yuk_hesaba_katilir(): void
    {
        // 1 numaralı hocanın geçmiş yükü çok; tek görev 2'ye gitmeli.
        $data = InvigilationFactory::make(
            2,
            [1 => ['slot' => 1, 'size' => 10]],
            pastDuty: [1 => 20, 2 => 0],
        );

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertSame([2], $result->assignments[1]);
    }

    public function test_dersin_sorumlusu_kendi_sinavinda_bulunur(): void
    {
        // 5 numaralı hocanın yükü yüksek olmasına rağmen kendi dersinde olmalı.
        $data = InvigilationFactory::make(
            6,
            [1 => ['slot' => 1, 'size' => 10, 'lecturer' => 5]],
            pastDuty: [5 => 50],
        );

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertSame([5], $result->assignments[1]);
    }

    public function test_sorumlu_musait_degilse_baskasi_atanir(): void
    {
        $data = InvigilationFactory::make(
            3,
            [1 => ['slot' => 1, 'size' => 10, 'lecturer' => 2]],
            unavailable: [2 => [1]],
        );

        $result = (new InvigilationAssigner($data))->assign();

        $this->assertNotContains(2, $result->assignments[1]);
        $this->assertCount(1, $result->assignments[1]);
    }

    public function test_dengeleme_sapmayi_dusurur(): void
    {
        $data = InvigilationFactory::facultyScale();
        $result = (new InvigilationAssigner($data))->assign();

        $this->assertLessThan(
            $result->deviationBefore,
            $result->deviationAfter,
            'Dengeleme adımı yük sapmasını düşürmeliydi',
        );

        $this->assertGreaterThan(0, $result->balanceMoves);
    }

    public function test_dengeleme_kural_bozmaz(): void
    {
        $data = InvigilationFactory::facultyScale();
        $result = (new InvigilationAssigner($data))->assign();

        $violations = (new InvigilationChecker)->check($result->assignments, $data);

        $this->assertSame([], array_map(strval(...), $violations));
    }

    public function test_uzun_sinav_daha_agir_sayilir(): void
    {
        // 120 dakikalık sınav 2 birim, 60 dakikalık 1 birim yük getirir.
        $data = InvigilationFactory::make(2, [
            1 => ['slot' => 1, 'size' => 10, 'duration' => 120],
            2 => ['slot' => 2, 'size' => 10, 'duration' => 60],
        ]);

        $result = (new InvigilationAssigner($data))->assign();

        $first = $result->assignments[1][0];
        $second = $result->assignments[2][0];

        $this->assertNotSame($first, $second, 'İkinci görev, yükü düşük olana gitmeli');
        $this->assertSame(2, $result->assignedWeight[$first]);
        $this->assertSame(1, $result->assignedWeight[$second]);
    }

    public function test_sonuc_tekrar_uretilebilir(): void
    {
        $data = InvigilationFactory::facultyScale();

        $first = (new InvigilationAssigner($data))->assign();
        $second = (new InvigilationAssigner($data))->assign();

        $this->assertSame($first->assignments, $second->assignments);
        $this->assertSame($first->loads, $second->loads);
    }

    public function test_denetleyici_k4_ihlalini_yakalar(): void
    {
        $data = InvigilationFactory::make(
            2,
            [1 => ['slot' => 1, 'size' => 10]],
            unavailable: [1 => [1]],
        );

        // Elle bozulmuş atama: müsait olmayan hoca yazılmış.
        $violations = (new InvigilationChecker)->check([1 => [1]], $data);

        $this->assertCount(1, $violations);
        $this->assertSame('K4', $violations[0]->code);
    }

    public function test_denetleyici_ayni_saatte_cift_gorevi_yakalar(): void
    {
        $data = InvigilationFactory::make(2, [
            1 => ['slot' => 1, 'size' => 10],
            2 => ['slot' => 1, 'size' => 10],
        ]);

        $codes = array_map(
            fn ($v) => $v->code,
            (new InvigilationChecker)->check([1 => [1], 2 => [1]], $data),
        );

        $this->assertContains('G1', $codes);
    }

    public function test_standart_sapma_hesabi(): void
    {
        $this->assertSame(0.0, InvigilationResult::deviation([5, 5, 5]));
        $this->assertSame(0.0, InvigilationResult::deviation([]));
        $this->assertSame(1.0, InvigilationResult::deviation([1, 3]));
    }
}
