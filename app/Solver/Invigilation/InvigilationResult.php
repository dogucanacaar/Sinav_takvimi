<?php

namespace App\Solver\Invigilation;

/**
 * Gözetmen atamasının sonucu ve başarı ölçütü.
 *
 * Ölçüt yüklerin standart sapmasıdır. Toplam görev sayısı zaten sabittir;
 * mesele o görevlerin ne kadar eşit dağıldığıdır. Sapma, "kimse ötekinin
 * iki katı görev almasın" cümlesinin sayıya çevrilmiş hâlidir.
 */
final class InvigilationResult
{
    /**
     * @param  array<int,int[]>  $assignments  examId => öğretim üyesi id'leri
     * @param  array<int,int>  $loads  lecturerId => toplam yük (geçmiş + bu dönem)
     * @param  array<int,int>  $assignedWeight  lecturerId => bu dönem eklenen yük
     * @param  array<int,int>  $shortages  examId => bulunamayan gözetmen sayısı
     */
    public function __construct(
        public readonly array $assignments,
        public readonly array $loads,
        public readonly array $assignedWeight,
        public readonly array $shortages,
        public readonly float $deviationBefore,
        public readonly float $deviationAfter,
        public readonly int $balanceMoves,
        public readonly float $elapsedSeconds,
    ) {}

    public function dutyCount(): int
    {
        return array_sum(array_map(count(...), $this->assignments));
    }

    public function hasShortage(): bool
    {
        return $this->shortages !== [];
    }

    public function improvementPercent(): float
    {
        if ($this->deviationBefore <= 0.0) {
            return 0.0;
        }

        return round(($this->deviationBefore - $this->deviationAfter) / $this->deviationBefore * 100, 1);
    }

    /** Standart sapma. */
    public static function deviation(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / $count);
    }

    public function toArray(): array
    {
        $loads = array_values($this->loads);

        return [
            'gorev_sayisi' => $this->dutyCount(),
            'ogretim_uyesi' => count($this->loads),
            'sapma_once' => round($this->deviationBefore, 2),
            'sapma_sonra' => round($this->deviationAfter, 2),
            'iyilesme_yuzde' => $this->improvementPercent(),
            'en_dusuk_yuk' => $loads === [] ? 0 : min($loads),
            'en_yuksek_yuk' => $loads === [] ? 0 : max($loads),
            'dengeleme_hamlesi' => $this->balanceMoves,
            'eksik_gozetmen' => array_sum($this->shortages),
            'sure_sn' => round($this->elapsedSeconds, 2),
        ];
    }
}
