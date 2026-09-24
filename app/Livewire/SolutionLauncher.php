<?php

namespace App\Livewire;

use App\Models\Solution;
use App\Services\SolveService;
use App\Support\ProgressStore;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Program üretme ekranı.
 *
 * "Üret" düğmesi çözümü burada çalıştırmaz; kuyruğa bırakır ve sayfa
 * hemen döner. Arama dakikalarca sürebilir, bir HTTP isteği bu kadar
 * bekleyemez.
 *
 * İlerleme önbellekten okunur (wire:poll). Motor her iki bin iterasyonda
 * bir oraya tek bir satır yazar — çözüm kaydına yazsaydı arama kadar uzun
 * süren bir yazma trafiği doğardı. Önbellek burada isteğe bağlı bir süstür:
 * yazılamazsa ilerleme çubuğu kaybolur, çözüm kaybolmaz (bkz. ProgressStore).
 */
#[Layout('components.layouts.app')]
class SolutionLauncher extends Component
{
    public string $label = '';

    public ?int $seed = null;

    public int $maxIter = 500000;

    public float $startTemp = 100;

    public float $cooling = 0.9995;

    public array $weights = ['E1' => 10, 'E2' => 25, 'E3' => 15, 'E4' => 1];

    public ?int $watching = null;

    public ?string $failure = null;

    /** Bu süreden uzun süre kuyrukta bekleyen iş takılmış sayılır. */
    private const QUEUE_GRACE_SECONDS = 15;

    public function mount(): void
    {
        // Yetki kontrolü rotadaki `can:manage-solutions` ara katmanında;
        // launch() ayrıca kendi kontrolünü yapar.
        // Sayfa yenilendiğinde devam eden işin takibi kaybolmamalı:
        // kullanıcı "queued" satırını tabloda görüp ne yapacağını bilemez.
        // Bekleyen ya da çalışan bir iş varsa doğrudan onu izlemeye devam et.
        $this->watching = Solution::whereIn('status', [Solution::QUEUED, Solution::RUNNING])
            ->orderByDesc('id')
            ->value('id');

        $this->weights = config('scheduling.weights');
        $this->maxIter = config('scheduling.annealing.max_iter');
        $this->startTemp = config('scheduling.annealing.start_temp');
        $this->cooling = config('scheduling.annealing.cooling');
    }

    public function rules(): array
    {
        return [
            'label' => 'nullable|string|max:120',
            'seed' => 'nullable|integer',
            'maxIter' => 'required|integer|min:1000|max:5000000',
            'startTemp' => 'required|numeric|min:1',
            'cooling' => 'required|numeric|min:0.9|max:0.99999',
            'weights.E1' => 'required|integer|min:0',
            'weights.E2' => 'required|integer|min:0',
            'weights.E3' => 'required|integer|min:0',
            'weights.E4' => 'required|integer|min:0',
        ];
    }

    public function launch(SolveService $service): void
    {
        abort_unless(Gate::allows('manage-solutions'), 403);

        $this->validate();

        // Önceki denemenin uyarısı yeni işin yanında asılı kalmasın.
        $this->failure = null;

        $solution = $service->start(
            auth()->user()->tenant_id,
            [
                'seed' => $this->seed,
                'max_iter' => $this->maxIter,
                'start_temp' => $this->startTemp,
                'cooling' => $this->cooling,
                'weights' => $this->weights,
            ],
            $this->label ?: null,
        );

        $this->watching = $solution->id;
    }

    /** wire:poll bu değeri okur. */
    public function getProgressProperty(): ?array
    {
        if ($this->watching === null) {
            return null;
        }

        return ProgressStore::get("solve:{$this->watching}");
    }

    /**
     * Kuyruk işçisi işi almıyor mu?
     *
     * İş kuyruğa bırakıldıktan sonra saniyeler içinde alınmalı. Alınmıyorsa
     * işçi süreci çalışmıyor demektir ve kullanıcı bunu bilmeden sonsuza
     * kadar "kuyrukta bekliyor" yazısına bakar. Sessizce beklemektense
     * sebebini ve ne yapılacağını söylemek gerekir.
     */
    public function queueStalled(?Solution $solution): bool
    {
        if ($solution === null || $solution->status !== Solution::QUEUED) {
            return false;
        }

        return $solution->created_at?->diffInSeconds(now()) > self::QUEUE_GRACE_SECONDS;
    }

    /**
     * Çözümü kuyruk beklemeden, bu istek içinde üretir.
     *
     * Normalde bu iş kuyruğa aittir. Ama kuyruk işçisi çalışmadığında
     * kullanıcının tek seçeneği komut satırına inmek oluyordu; küçük bir
     * fakülte için üretim birkaç saniye sürdüğünden bunu doğrudan
     * yapabilmek makul bir çıkış yolu.
     */
    public function runNow(SolveService $service): void
    {
        abort_unless(Gate::allows('manage-solutions'), 403);

        $this->failure = null;

        $solution = $this->watching !== null ? Solution::find($this->watching) : null;

        // Sessizce dönmek en kötü davranış: kullanıcı düğmeye basar, hiçbir
        // şey olmaz ve sebebini bilemez. Her çıkış yolu bir cümle bırakır.
        if ($solution === null) {
            $this->failure = 'İzlenen çözüm bulunamadı. Sayfayı yenileyip tekrar deneyin.';

            return;
        }

        if ($solution->status === Solution::RUNNING) {
            $this->failure = 'Bu çözüm şu anda zaten üretiliyor.';

            return;
        }

        if ($solution->status !== Solution::QUEUED) {
            $this->failure = 'Bu çözüm kuyrukta beklemiyor (durum: '.$solution->status.').';

            return;
        }

        // Uzun sürebilir; PHP'nin istek zaman aşımı kaldırılır.
        set_time_limit(0);

        try {
            $service->execute($solution);
        } catch (\Throwable $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render()
    {
        $solutions = Solution::query()
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $watched = $this->watching !== null ? Solution::find($this->watching) : null;

        // Uyarı burada temizlenmiyor: düğmeye basan kullanıcı, basışının
        // neden bir şey yapmadığını okuyabilmeli. Kalması gereken yerde
        // kalsın diye yalnızca yeni bir iş başlatılınca siliniyor
        // (bkz. launch()); yoksa eski işin uyarısı yeni işin yanında
        // asılı kalırdı.
        return view('livewire.solution-launcher', [
            'solutions' => $solutions,
            'watched' => $watched,
            'progress' => $this->progress,
            'stalled' => $this->queueStalled($watched),
        ]);
    }
}
