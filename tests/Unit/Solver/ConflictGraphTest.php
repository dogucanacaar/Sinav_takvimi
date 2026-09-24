<?php

namespace Tests\Unit\Solver;

use App\Solver\ConflictGraph;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProblemFactory;

/**
 * Çakışma grafiği yanlışsa her şey yanlıştır: motor olmayan bir çakışmadan
 * kaçınır ya da olan bir çakışmayı görmez. Bu yüzden en temel test budur.
 */
final class ConflictGraphTest extends TestCase
{
    public function test_ortak_ogrencili_dersler_komsudur(): void
    {
        $conflicts = ConflictGraph::build([
            1 => [101, 103],
            2 => [101, 102],
            3 => [102, 103],
        ]);

        $this->assertContains(2, $conflicts[1], '1 ve 2 ortak öğrenci 101 üzerinden komşu olmalı');
        $this->assertContains(3, $conflicts[1], '1 ve 3 ortak öğrenci 103 üzerinden komşu olmalı');
        $this->assertContains(3, $conflicts[2], '2 ve 3 ortak öğrenci 102 üzerinden komşu olmalı');
    }

    public function test_ortak_ogrencisi_olmayan_dersler_komsu_degildir(): void
    {
        $conflicts = ConflictGraph::build([
            1 => [101],
            2 => [102],
        ]);

        $this->assertSame([], $conflicts[1]);
        $this->assertSame([], $conflicts[2]);
    }

    public function test_komsuluk_cift_yonludur_ve_tekrar_etmez(): void
    {
        $conflicts = ConflictGraph::build([
            1 => [101, 102, 103],
            2 => [101, 102, 103],
        ]);

        // Üç ortak öğrenci var ama komşuluk bir kez yazılmalı.
        $this->assertSame([2], $conflicts[1]);
        $this->assertSame([1], $conflicts[2]);
    }

    public function test_ders_kendisiyle_komsu_olmaz(): void
    {
        $conflicts = ConflictGraph::build([1 => [101, 102]]);

        $this->assertNotContains(1, $conflicts[1]);
    }

    public function test_derece_sirasi_azalan_olur(): void
    {
        $data = ProblemFactory::small();
        $order = ConflictGraph::orderByDegree($data->conflicts);

        $previous = PHP_INT_MAX;

        foreach ($order as $examId) {
            $degree = $data->conflictDegree($examId);
            $this->assertLessThanOrEqual($previous, $degree, 'Sıralama azalan olmalı');
            $previous = $degree;
        }
    }

    public function test_bos_kayit_listesi_bos_grafik_uretir(): void
    {
        $this->assertSame([], ConflictGraph::build([]));
    }
}
