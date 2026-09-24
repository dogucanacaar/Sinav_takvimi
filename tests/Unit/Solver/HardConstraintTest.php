<?php

namespace Tests\Unit\Solver;

use App\Solver\ConflictGraph;
use App\Solver\HardConstraintChecker;
use App\Solver\HardConstraints;
use App\Solver\Moves\MoveExam;
use App\Solver\Moves\SwapExams;
use App\Solver\ProblemData;
use App\Solver\Schedule;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProblemFactory;

/**
 * K1–K5'in her biri için bilerek bozulmuş bir çizelge kurulur ve
 * denetleyicinin bunu yakaladığı doğrulanır.
 *
 * Testin yönü önemli: "geçerli çizelgeye geçerli dedi" yeterli değildir.
 * Asıl değer, geçersiz olanı yakalamasındadır.
 */
final class HardConstraintTest extends TestCase
{
    public function test_gecerli_cizelgede_ihlal_bulunmaz(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);
        $schedule->assign(2, 2, 1);
        $schedule->assign(3, 3, 1);
        $schedule->assign(4, 4, 1);
        $schedule->assign(5, 5, 1);

        $this->assertSame([], (new HardConstraintChecker)->check($schedule, $data));
    }

    public function test_k1_ayni_ogrencinin_iki_sinavi_ayni_saatte_yakalanir(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);
        $schedule->assign(2, 1, 2); // 101 numaralı öğrenci her ikisine de giriyor
        $schedule->assign(3, 3, 1);
        $schedule->assign(4, 4, 1);
        $schedule->assign(5, 5, 1);

        $codes = array_column(
            array_map(fn ($v) => $v->toArray(), (new HardConstraintChecker)->check($schedule, $data)),
            'code'
        );

        $this->assertContains('K1', $codes);
    }

    public function test_k2_ayni_yere_ikinci_sinav_yerlesemez(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(4, 1, 1);
        $schedule->assign(5, 1, 1); // aynı (saat, derslik) ikilisi

        // Schedule veri yapısı K2'yi kökünden engeller: ikinci atama
        // birincinin yerini alır, iki sınav aynı yerde duramaz.
        $this->assertSame(1, $schedule->assignedCount(), 'Aynı yerde iki sınav tutulmamalı');
        $this->assertSame(5, $schedule->occupantOf(1, 1));
        $this->assertFalse($schedule->isAssigned(4));

        // Denetleyici de aynı sonuca varır: kalan çizelgede K2 ihlali yoktur.
        $violations = (new HardConstraintChecker)->check($schedule, $data, requireAll: false);
        $this->assertSame([], $violations);
    }

    public function test_k3_kapasite_asimi_yakalanir(): void
    {
        $examStudents = [1 => range(101, 200)]; // 100 öğrenci

        $data = ProblemData::build(
            examIds: [1],
            examSize: [1 => 100],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1],
            slotMeta: [1 => ['day' => '2026-06-12', 'index' => 1]],
            roomCapacity: [1 => 30],
            roomBuilding: [1 => 1],
        );

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);

        $codes = array_map(fn ($v) => $v->code, (new HardConstraintChecker)->check($schedule, $data));

        $this->assertContains('K3', $codes);
    }

    public function test_k5_yerlesmemis_sinav_yakalanir(): void
    {
        $data = ProblemFactory::small();

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);
        // 2, 3, 4, 5 yerleştirilmedi

        $codes = array_map(fn ($v) => $v->code, (new HardConstraintChecker)->check($schedule, $data));

        $this->assertSame(['K5', 'K5', 'K5', 'K5'], $codes);
    }

    public function test_kural_bozacak_hamleye_izin_verilmez(): void
    {
        $data = ProblemFactory::small();
        $hard = new HardConstraints($data);

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);
        $schedule->assign(2, 2, 1);

        // 2'yi 1'in saatine taşımak K1'i bozar (ortak öğrenci 101).
        $move = MoveExam::from($schedule, 2, 1, 2);
        $this->assertFalse($hard->allows($schedule, $move), 'K1 bozan hamle reddedilmeli');

        // Dolu bir dersliğe taşımak K2'yi bozar.
        $move = MoveExam::from($schedule, 2, 1, 1);
        $this->assertFalse($hard->allows($schedule, $move), 'K2 bozan hamle reddedilmeli');

        // Boş ve çakışmasız bir yere taşımak serbesttir.
        $move = MoveExam::from($schedule, 2, 3, 1);
        $this->assertTrue($hard->allows($schedule, $move));
    }

    public function test_kapasitesi_yetmeyen_derslige_hamleye_izin_verilmez(): void
    {
        $examStudents = [1 => range(101, 140)];

        $data = ProblemData::build(
            examIds: [1],
            examSize: [1 => 40],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1, 2],
            slotMeta: [
                1 => ['day' => '2026-06-12', 'index' => 1],
                2 => ['day' => '2026-06-12', 'index' => 2],
            ],
            roomCapacity: [1 => 50, 2 => 30],
            roomBuilding: [1 => 1, 2 => 1],
        );

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);

        $hard = new HardConstraints($data);

        $this->assertFalse($hard->allows($schedule, MoveExam::from($schedule, 1, 2, 2)), 'K3 bozan hamle reddedilmeli');
        $this->assertTrue($hard->allows($schedule, MoveExam::from($schedule, 1, 2, 1)));
    }

    public function test_takas_hamlesinde_iki_sinav_da_yerinden_kalkmis_sayilir(): void
    {
        $data = ProblemFactory::small();
        $hard = new HardConstraints($data);

        $schedule = new Schedule;
        $schedule->assign(4, 1, 1);
        $schedule->assign(5, 2, 2);

        // 4 ve 5 kimseyle çakışmıyor; yer değiştirmeleri geçerli olmalı.
        // Naif bir kontrol "hedef dolu" diyip reddederdi.
        $swap = SwapExams::from($schedule, 4, 5);

        $this->assertTrue($hard->allows($schedule, $swap));
    }

    public function test_takas_kati_kurali_bozuyorsa_reddedilir(): void
    {
        $data = ProblemFactory::small();
        $hard = new HardConstraints($data);

        // 1 ile 2 çakışır (ortak öğrenci 101), 1 ile 3 çakışır (ortak öğrenci 103).
        $schedule = new Schedule;
        $schedule->assign(1, 1, 1);
        $schedule->assign(2, 2, 2);
        $schedule->assign(3, 3, 2);

        // 2 ile 3'ü takas etmek kural bozmaz: ikisi de 1'in saatine gitmiyor.
        $this->assertTrue($hard->allows($schedule, SwapExams::from($schedule, 2, 3)));

        // 1 ile 2'yi takas etmek de bozmaz: sadece yerleri değişir.
        $this->assertTrue($hard->allows($schedule, SwapExams::from($schedule, 1, 2)));

        // Ama 2'yi 1'in saatine taşımak K1'i bozar — takas değil, düz taşıma.
        $this->assertFalse($hard->allows($schedule, MoveExam::from($schedule, 2, 1, 2)));
    }

    public function test_kapasite_yetmezse_takas_reddedilir(): void
    {
        $examStudents = [1 => range(101, 140), 2 => range(201, 205)];

        $data = ProblemData::build(
            examIds: [1, 2],
            examSize: [1 => 40, 2 => 5],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1, 2],
            slotMeta: [
                1 => ['day' => '2026-06-12', 'index' => 1],
                2 => ['day' => '2026-06-12', 'index' => 2],
            ],
            roomCapacity: [1 => 50, 2 => 10],
            roomBuilding: [1 => 1, 2 => 1],
        );

        $schedule = new Schedule;
        $schedule->assign(1, 1, 1); // 40 kişi, 50 kapasiteli derslikte
        $schedule->assign(2, 2, 2); // 5 kişi, 10 kapasiteli derslikte

        // Takas edilirse 40 kişilik sınav 10 kapasiteli dersliğe düşer: K3 bozulur.
        $this->assertFalse($hard = (new HardConstraints($data))->allows($schedule, SwapExams::from($schedule, 1, 2)));
    }
}
