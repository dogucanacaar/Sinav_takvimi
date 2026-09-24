<?php

namespace App\Solver\Invigilation;

/**
 * Gözetmen ataması için gereken her şeyin bellekteki hâli.
 *
 * Çizelgeleme motoru gibi bu da veritabanını bilmez: çizelge artık
 * hazırdır ve burada sadece "hangi sınav hangi saatte" bilgisi kullanılır.
 */
final class InvigilationData
{
    /**
     * @param  int[]  $lecturerIds
     * @param  array<int,int>  $pastDuty  lecturerId => geçmiş dönem görev sayısı
     * @param  array<int,array<int,true>>  $unavailable  lecturerId => slotId => true (K4)
     * @param  array<int,int>  $examSlot  examId => slotId
     * @param  array<int,int>  $examSize  examId => öğrenci sayısı
     * @param  array<int,int>  $examDuration  examId => sınav süresi (dakika)
     * @param  array<int,int|null>  $examLecturer  examId => dersin sorumlusu
     */
    public function __construct(
        public readonly array $lecturerIds,
        public readonly array $pastDuty,
        public readonly array $unavailable,
        public readonly array $examSlot,
        public readonly array $examSize,
        public readonly array $examDuration = [],
        public readonly array $examLecturer = [],
    ) {}

    public function isAvailable(int $lecturerId, int $slotId): bool
    {
        return ! isset($this->unavailable[$lecturerId][$slotId]);
    }

    /**
     * Bir görevin yük ağırlığı.
     *
     * Her görev eşit değildir: 60 dakikalık bir sınavla 120 dakikalık bir
     * sınav aynı yükü getirmez. Saat başına bir birim sayılır, en az 1.
     */
    public function dutyWeight(int $examId): int
    {
        $duration = $this->examDuration[$examId] ?? 60;

        return max(1, (int) ceil($duration / 60));
    }

    /** Sınav için gereken gözetmen sayısı. */
    public function requiredInvigilators(int $examId, int $perInvigilator = 40, int $minimum = 1): int
    {
        $size = $this->examSize[$examId] ?? 0;

        return max($minimum, (int) ceil($size / max(1, $perInvigilator)));
    }

    /** @return int[] öğrenci sayısı azalan sırada sınav id'leri */
    public function examsBySizeDesc(): array
    {
        $sizes = array_intersect_key($this->examSize, $this->examSlot);

        // Eşit büyüklükte id'ye göre sırala: sonuç tekrar üretilebilir olsun.
        uksort($sizes, fn (int $a, int $b): int => [$sizes[$b], $a] <=> [$sizes[$a], $b]);

        return array_keys($sizes);
    }
}
