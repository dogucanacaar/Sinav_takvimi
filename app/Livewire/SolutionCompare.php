<?php

namespace App\Livewire;

use App\Models\Solution;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * İki çözümü yan yana koyar.
 *
 * Tek bir ceza puanı "daha iyi mi?" sorusunu cevaplamaz. 40.000 puanlı
 * bir çözüm 45.000 puanlıdan düşüktür ama farkın nereden geldiği
 * önemlidir: E2 mi düştü yoksa sadece derslik israfı mı azaldı?
 * Bölüm başkanının umursadığı şey genelde tek bir kalem olur.
 */
#[Layout('components.layouts.app')]
class SolutionCompare extends Component
{
    public ?int $leftId = null;

    public ?int $rightId = null;

    public function mount(): void
    {
        $recent = Solution::where('status', Solution::COMPLETED)
            ->orderByDesc('id')
            ->limit(2)
            ->pluck('id');

        $this->leftId = $recent[0] ?? null;
        $this->rightId = $recent[1] ?? null;
    }

    /** Karşılaştırma satırları: etiket, sol değer, sağ değer, hangisi daha iyi. */
    public function rows(?Solution $left, ?Solution $right): array
    {
        $get = function (?Solution $solution, string ...$path) {
            $value = $solution?->stats ?? [];

            foreach ($path as $key) {
                $value = $value[$key] ?? null;

                if ($value === null) {
                    return null;
                }
            }

            return $value;
        };

        return [
            ['Ceza puanı', $left?->penalty, $right?->penalty, 'lower'],
            ['E1 — aynı gün birden fazla sınav (adet)', $get($left, 'breakdown', 'counts', 'E1'), $get($right, 'breakdown', 'counts', 'E1'), 'lower'],
            ['E2 — art arda saat dilimi (adet)', $get($left, 'breakdown', 'counts', 'E2'), $get($right, 'breakdown', 'counts', 'E2'), 'lower'],
            ['E3 — aynı gün farklı bina (adet)', $get($left, 'breakdown', 'counts', 'E3'), $get($right, 'breakdown', 'counts', 'E3'), 'lower'],
            ['E4 — kapasite israfı (puan)', $get($left, 'breakdown', 'E4'), $get($right, 'breakdown', 'E4'), 'lower'],
            ['Yerleşemeyen sınav', $get($left, 'unplaced_count'), $get($right, 'unplaced_count'), 'lower'],
            ['Gözetmen yük sapması', $get($left, 'invigilation', 'sapma_sonra'), $get($right, 'invigilation', 'sapma_sonra'), 'lower'],
            ['Eksik gözetmen', $get($left, 'invigilation', 'eksik_gozetmen'), $get($right, 'invigilation', 'eksik_gozetmen'), 'lower'],
            ['İterasyon', $get($left, 'iterations'), $get($right, 'iterations'), 'none'],
            ['Süre (sn)', $get($left, 'elapsed_seconds'), $get($right, 'elapsed_seconds'), 'none'],
        ];
    }

    /** Sayıları okunur hâle getirir: tam sayılar binlik ayraçla, ondalıklar iki basamakla. */
    public function format(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        return fmod((float) $value, 1.0) === 0.0
            ? number_format((float) $value)
            : number_format((float) $value, 2);
    }

    public function render()
    {
        $left = $this->leftId ? Solution::find($this->leftId) : null;
        $right = $this->rightId ? Solution::find($this->rightId) : null;

        foreach ([$left, $right] as $solution) {
            if ($solution !== null) {
                $this->authorize('view', $solution);
            }
        }

        return view('livewire.solution-compare', [
            'solutions' => Solution::orderByDesc('id')->limit(30)->get(),
            'left' => $left,
            'right' => $right,
            'rows' => $this->rows($left, $right),
        ]);
    }
}
