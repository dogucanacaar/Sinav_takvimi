<?php

namespace App\Solver;

/**
 * Çakışma grafiği: ortak öğrencisi olan iki sınav birbirinin komşusudur.
 *
 * K1 (aynı öğrencinin iki sınavı aynı saatte olamaz) kuralı bu grafiğe
 * indirgenir. Böylece kural kontrolü, her seferinde öğrenci listelerini
 * kesiştirmek yerine hazır komşu listesine bakmaya dönüşür.
 */
final class ConflictGraph
{
    /**
     * Bellekteki öğrenci listelerinden komşuluk üretir.
     *
     * @param  array<int,int[]>  $examStudents  examId => öğrenci id'leri
     * @return array<int,int[]> examId => komşu sınav id'leri
     */
    public static function build(array $examStudents): array
    {
        $conflicts = [];

        foreach (array_keys($examStudents) as $examId) {
            $conflicts[$examId] = [];
        }

        // Öğrenciden sınava ters indeks: bir öğrencinin girdiği tüm sınav
        // çiftleri doğrudan komşudur. Tüm sınav çiftlerini taramaktan
        // (O(sınav²)) çok daha ucuzdur.
        $studentExams = [];

        foreach ($examStudents as $examId => $studentIds) {
            foreach ($studentIds as $studentId) {
                $studentExams[$studentId][] = $examId;
            }
        }

        $seen = [];

        foreach ($studentExams as $examIds) {
            $examIds = array_values(array_unique($examIds));
            $count = count($examIds);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $examIds[$i];
                    $b = $examIds[$j];

                    $key = $a < $b ? "$a:$b" : "$b:$a";

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $conflicts[$a][] = $b;
                    $conflicts[$b][] = $a;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Veritabanından gelen (c1, c2) çiftlerini çift yönlü komşuluğa çevirir.
     *
     * @param  int[]  $examIds
     * @param  iterable<array{0:int,1:int}>  $pairs
     * @return array<int,int[]>
     */
    public static function fromPairs(array $examIds, iterable $pairs): array
    {
        $conflicts = array_fill_keys($examIds, []);

        foreach ($pairs as [$a, $b]) {
            $conflicts[$a][] = $b;
            $conflicts[$b][] = $a;
        }

        return $conflicts;
    }

    /**
     * Sınavları çakışma derecesine (komşu sayısı) göre azalan sırada döndürür.
     * Başlangıç çözümü bu sırayla ilerler: en kısıtlı sınav ilk yerleşir.
     *
     * @param  array<int,int[]>  $conflicts
     * @return int[]
     */
    public static function orderByDegree(array $conflicts): array
    {
        $degrees = array_map(count(...), $conflicts);

        // Eşit dereceli sınavlarda id'ye göre sırala: sonuç tekrar üretilebilir olsun.
        uksort($degrees, function (int $a, int $b) use ($degrees): int {
            return [$degrees[$b], $a] <=> [$degrees[$a], $b];
        });

        return array_keys($degrees);
    }
}
