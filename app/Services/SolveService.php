<?php

namespace App\Services;

use App\Jobs\SolveJob;
use App\Models\Solution;
use App\Solver\HardConstraintChecker;
use App\Solver\HardConstraints;
use App\Solver\InitialBuilder;
use App\Solver\Invigilation\InvigilationAssigner;
use App\Solver\Invigilation\InvigilationChecker;
use App\Solver\MoveGenerator;
use App\Solver\Penalty;
use App\Solver\Schedule;
use App\Solver\SimulatedAnnealing;
use App\Support\ProgressStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Çözüm üretiminin uygulama katmanı.
 *
 * İki ayrı iş yapar ve bunların ayrı olması önemlidir:
 *   start()   — kayıt açar, işi kuyruğa bırakır, hemen döner (HTTP isteği burada biter)
 *   execute() — asıl çözümü üretir; işçi süreçte, dakikalarca çalışabilir
 */
final class SolveService
{
    public function __construct(
        private readonly ProblemDataLoader $loader,
        private readonly InvigilationDataLoader $invigilationLoader,
    ) {}

    /**
     * Yeni bir çözüm kaydı açar — kuyruğa bırakmaz.
     *
     * Kayıt açmakla işi başlatmak ayrı tutulur. Birleşik olduğunda,
     * QUEUE_CONNECTION=sync olan bir ortamda (Laravel'in yaygın
     * varsayılanı) dispatch işi anında çalıştırıyor, ardından çağıran
     * kod execute() dediğinde aynı çözüm ikinci kez üretiliyordu.
     */
    public function create(int $tenantId, array $params = [], ?string $label = null): Solution
    {
        return Solution::create([
            'tenant_id' => $tenantId,
            'label' => $label ?? 'Çözüm '.now()->format('d.m.Y H:i'),
            'status' => Solution::QUEUED,
            'params' => $this->resolveParams($params),
        ]);
    }

    /** Kayıt açar ve işi kuyruğa bırakır — arayüzün kullandığı yol. */
    public function start(int $tenantId, array $params = [], ?string $label = null): Solution
    {
        $solution = $this->create($tenantId, $params, $label);

        SolveJob::dispatch($solution->id, $tenantId)->onQueue('solve');

        return $solution;
    }

    /**
     * Çözümü üretir ve kaydeder.
     *
     * @param  callable|null  $onProgress  ilerleme satırını dışarı vermek için (konsol çıktısı gibi)
     */
    public function execute(Solution $solution, ?callable $onProgress = null): Solution
    {
        $solution->update(['status' => Solution::RUNNING]);

        try {
            $data = $this->loader->load($solution->tenant_id);

            if ($data->examCount() === 0) {
                throw new \RuntimeException('Bu kurumda sınavı olan ders yok. Önce veri aktarın.');
            }

            $params = $solution->params;
            $penalty = new Penalty($data, $params['weights']);
            $hard = new HardConstraints($data);

            $built = (new InitialBuilder($data))->build();

            $engine = new SimulatedAnnealing(
                $data,
                $penalty,
                $hard,
                new MoveGenerator($data, $params['move_share'] ?? 70, $params['swap_share'] ?? 30),
            );

            $result = $engine->run(
                $built['schedule'],
                $params,
                function (array $progress) use ($solution, $onProgress): void {
                    // Arayüz bu anahtarı wire:poll ile okur. Yazamamak
                    // aramayı durdurmaz; ProgressStore hatayı yutar.
                    ProgressStore::put($solution->progressKey(), $progress);

                    if ($onProgress !== null) {
                        $onProgress($progress);
                    }
                },
                $built['unplaced'],
            );

            // Bağımsız denetleyici: motora güvenmeden, sıfırdan kontrol.
            // Yerleşemeyen sınavlar ayrı raporlandığı için requireAll: false.
            $violations = (new HardConstraintChecker)->check($result->schedule, $data, requireAll: false);

            if ($violations !== []) {
                // Motorda bir hata var demektir. Geçersiz program asla
                // geçerli gibi sunulmaz.
                $solution->update([
                    'status' => Solution::FAILED,
                    'penalty' => $result->bestPenalty,
                    'hard_violations' => count($violations),
                    'failure_reason' => 'Katı kural ihlali: '
                        .json_encode(HardConstraintChecker::summarize($violations), JSON_UNESCAPED_UNICODE),
                    'stats' => $result->toArray(),
                    'finished_at' => now(),
                ]);

                return $solution->refresh();
            }

            $this->persistEntries($solution, $result->schedule->entries());

            // Çizelge hazır; sıra gözetmenlerde.
            $invigilation = $this->assignInvigilators($solution, $result->schedule);

            $solution->update([
                'status' => Solution::COMPLETED,
                'penalty' => $result->bestPenalty,
                'hard_violations' => 0,
                'stats' => array_merge($result->toArray(), [
                    'breakdown' => $penalty->breakdown($result->schedule),
                    'unplaced_exam_ids' => array_slice($result->unplaced, 0, 50),
                    'invigilation' => $invigilation,
                ]),
                'finished_at' => now(),
            ]);

            ProgressStore::forget($solution->progressKey());

            return $solution->refresh();
        } catch (Throwable $e) {
            $solution->update([
                'status' => Solution::FAILED,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Gözetmenleri atar ve kaydeder.
     *
     * Çizelgeleme gibi burada da sonuç, atamayı yapan koddan bağımsız bir
     * denetleyiciden geçer. İhlal bulunursa atama yazılmaz: eksik bir
     * gözetmen çizelgesi, yanlış bir gözetmen çizelgesinden iyidir.
     *
     * @return array<string,mixed> istatistikler
     */
    private function assignInvigilators(Solution $solution, Schedule $schedule): array
    {
        $config = config('scheduling.invigilation');
        $data = $this->invigilationLoader->load($solution->tenant_id, $schedule);

        if ($data->lecturerIds === []) {
            return ['durum' => 'atlandi', 'sebep' => 'Kurumda öğretim üyesi tanımlı değil.'];
        }

        $assigner = new InvigilationAssigner(
            $data,
            studentsPerInvigilator: $config['students_per_invigilator'],
            minimumPerExam: $config['min_per_exam'],
            balanceIterations: $config['balance_iterations'],
        );

        $result = $assigner->assign();

        $violations = (new InvigilationChecker)->check(
            $result->assignments,
            $data,
            $config['students_per_invigilator'],
            $config['min_per_exam'],
        );

        if ($violations !== []) {
            return [
                'durum' => 'basarisiz',
                'ihlaller' => InvigilationChecker::summarize($violations),
            ];
        }

        $rows = [];

        foreach ($result->assignments as $examId => $lecturerIds) {
            foreach ($lecturerIds as $lecturerId) {
                $rows[] = [
                    'solution_id' => $solution->id,
                    'exam_id' => $examId,
                    'lecturer_id' => $lecturerId,
                ];
            }
        }

        DB::transaction(function () use ($solution, $rows): void {
            DB::table('invigilations')->where('solution_id', $solution->id)->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('invigilations')->insert($chunk);
            }
        });

        return array_merge(['durum' => 'tamam'], $result->toArray());
    }

    /** @param  array<int,array{0:int,1:int}>  $entries */
    private function persistEntries(Solution $solution, array $entries): void
    {
        $rows = [];

        foreach ($entries as $examId => [$slotId, $roomId]) {
            $rows[] = [
                'solution_id' => $solution->id,
                'exam_id' => $examId,
                'slot_id' => $slotId,
                'room_id' => $roomId,
            ];
        }

        DB::transaction(function () use ($solution, $rows): void {
            DB::table('schedule_entries')->where('solution_id', $solution->id)->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('schedule_entries')->insert($chunk);
            }
        });
    }

    /** Kullanıcıdan gelen parametreleri varsayılanlarla birleştirir. */
    private function resolveParams(array $params): array
    {
        $defaults = config('scheduling.annealing');
        $defaults['weights'] = config('scheduling.weights');

        return array_replace($defaults, array_filter(
            $params,
            fn ($value) => $value !== null && $value !== '',
        ));
    }
}
