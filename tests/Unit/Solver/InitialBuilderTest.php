<?php

namespace Tests\Unit\Solver;

use App\Solver\ConflictGraph;
use App\Solver\HardConstraintChecker;
use App\Solver\InitialBuilder;
use App\Solver\ProblemData;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProblemFactory;

/**
 * Başlangıç çözümünün tek görevi vardır: geçerli bir çizelge üretmek.
 * İyi olması beklenmez, ama katı kuralı bozmaması şarttır — çünkü
 * tavlama benzetimi geçerli bir çizelgeden başladığını varsayar.
 */
final class InitialBuilderTest extends TestCase
{
    public function test_kucuk_problemde_tum_sinavlar_yerlesir(): void
    {
        $data = ProblemFactory::small();
        $result = (new InitialBuilder($data))->build();

        $this->assertSame([], $result['unplaced'], 'Küçük problemde yerleşemeyen sınav kalmamalı');
        $this->assertSame(count($data->examIds), $result['schedule']->assignedCount());
    }

    public function test_uretilen_ilk_cizelgede_kati_ihlal_yoktur(): void
    {
        $data = ProblemFactory::random(seed: 7);
        $result = (new InitialBuilder($data))->build();

        $violations = (new HardConstraintChecker)->check($result['schedule'], $data, requireAll: false);

        $this->assertSame(
            [],
            array_map(strval(...), $violations),
            'Başlangıç çözümü katı kural bozmamalı'
        );
    }

    public function test_yerlesemeyen_sinav_raporlanir_hata_firlatilmaz(): void
    {
        // Tek saat dilimi, tek küçük derslik: çakışan sınavlar sığmaz.
        $examStudents = [1 => [101], 2 => [101], 3 => [101]];

        $data = ProblemData::build(
            examIds: [1, 2, 3],
            examSize: [1 => 1, 2 => 1, 3 => 1],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1],
            slotMeta: [1 => ['day' => '2026-06-12', 'index' => 1]],
            roomCapacity: [1 => 10],
            roomBuilding: [1 => 1],
        );

        $result = (new InitialBuilder($data))->build();

        $this->assertSame(1, $result['schedule']->assignedCount());
        $this->assertCount(2, $result['unplaced']);
    }

    public function test_yeterli_olan_en_kucuk_derslik_secilir(): void
    {
        $examStudents = [1 => range(101, 125)]; // 25 öğrenci

        $data = ProblemData::build(
            examIds: [1],
            examSize: [1 => 25],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1],
            slotMeta: [1 => ['day' => '2026-06-12', 'index' => 1]],
            roomCapacity: [1 => 100, 2 => 30, 3 => 20],
            roomBuilding: [1 => 1, 2 => 1, 3 => 1],
        );

        $result = (new InitialBuilder($data))->build();

        $this->assertSame(2, $result['schedule']->roomOf(1), '30 kişilik derslik seçilmeliydi');
    }
}
