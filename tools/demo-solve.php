<?php

/**
 * Motoru gerçek boyutta, veritabanı olmadan çalıştırır.
 *
 * 120 ders, 2500 öğrenci, 30 derslik, 5 gün × 4 saat dilimi —
 * yani DemoSeeder'ın ürettiği fakülteyle aynı boyut.
 *
 * Amacı sunumdur: ceza puanının gerçekten düştüğünü ve sonuç
 * çizelgesinin katı kural bozmadığını, hiçbir kurulum yapmadan
 * tek komutla göstermek.
 *
 *   php tools/demo-solve.php
 *   php tools/demo-solve.php --seed=42 --max-iter=200000
 */

declare(strict_types=1);

$root = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($root): void {
    foreach (['App\\' => $root.'/app/', 'Tests\\' => $root.'/tests/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});

use App\Solver\HardConstraintChecker;
use App\Solver\HardConstraints;
use App\Solver\InitialBuilder;
use App\Solver\Invigilation\InvigilationAssigner;
use App\Solver\Invigilation\InvigilationChecker;
use App\Solver\MoveGenerator;
use App\Solver\Penalty;
use App\Solver\SimulatedAnnealing;
use Tests\Support\InvigilationFactory;
use Tests\Support\ProblemFactory;

// --- Parametreler -----------------------------------------------------------

$options = getopt('', ['seed::', 'max-iter::', 'cooling::', 'start-temp::', 'quiet']);

$seed = isset($options['seed']) ? (int) $options['seed'] : 2026;
$maxIter = isset($options['max-iter']) ? (int) $options['max-iter'] : 300000;
$cooling = isset($options['cooling']) ? (float) $options['cooling'] : 0.99995;
$startTemp = isset($options['start-temp']) ? (float) $options['start-temp'] : 100.0;
$quiet = isset($options['quiet']);

$weights = ['E1' => 10, 'E2' => 25, 'E3' => 15, 'E4' => 1];

function line(string $text = ''): void
{
    echo $text."\n";
}

// --- Problem ----------------------------------------------------------------

line('Problem kuruluyor (120 ders, 2500 öğrenci, 30 derslik, 5 gün × 4 saat)...');

$t0 = microtime(true);
$data = ProblemFactory::facultyScale();
$buildTime = microtime(true) - $t0;

$degrees = array_map(count(...), $data->conflicts);

line(sprintf(
    '  %d sınav, %d öğrenci, %d kayıt, %d saat dilimi, %d derslik  (%.2f sn)',
    $data->examCount(),
    count($data->studentExams),
    array_sum(array_map(count(...), $data->examStudents)),
    count($data->slotIds),
    count($data->roomCapacity),
    $buildTime,
));

line(sprintf(
    '  Sınav büyüklüğü: en küçük %d, ortalama %d, en büyük %d',
    min($data->examSize),
    (int) round(array_sum($data->examSize) / count($data->examSize)),
    max($data->examSize),
));

line(sprintf(
    '  Çakışma derecesi: ortalama %d, en yüksek %d (%d sınav üzerinden)',
    (int) round(array_sum($degrees) / count($degrees)),
    max($degrees),
    count($degrees),
));

// --- Başlangıç çözümü -------------------------------------------------------

line();
line('Başlangıç çözümü üretiliyor...');

$t0 = microtime(true);
$built = (new InitialBuilder($data))->build();
$initialTime = microtime(true) - $t0;

$schedule = $built['schedule'];

line(sprintf(
    '  %d / %d sınav yerleşti, %d yerleşemedi  (%.2f sn)',
    $schedule->assignedCount(),
    $data->examCount(),
    count($built['unplaced']),
    $initialTime,
));

$checker = new HardConstraintChecker;
$violations = $checker->check($schedule, $data, requireAll: false);

line('  Katı kural ihlali: '.(count($violations) === 0 ? 'yok' : json_encode(HardConstraintChecker::summarize($violations))));

$penalty = new Penalty($data, $weights);
$before = $penalty->breakdown($schedule);

// --- Tavlama benzetimi ------------------------------------------------------

line();
line(sprintf('Tavlama benzetimi (tohum=%d, max_iter=%d, cooling=%s)...', $seed, $maxIter, $cooling));

$engine = new SimulatedAnnealing($data, $penalty, new HardConstraints($data), new MoveGenerator($data));

$history = [];

$result = $engine->run($schedule, [
    'start_temp' => $startTemp,
    'min_temp' => 0.01,
    'cooling' => $cooling,
    'max_iter' => $maxIter,
    'seed' => $seed,
    'progress_every' => max(1000, (int) ($maxIter / 30)),
], function (array $progress) use (&$history, $quiet): void {
    $history[] = $progress;

    if (! $quiet) {
        printf(
            "  %7d / %d   puan: %7d   en iyi: %7d   sıcaklık: %8.3f\n",
            $progress['iter'], $progress['max_iter'], $progress['penalty'], $progress['best'], $progress['temp'],
        );
    }
}, $built['unplaced']);

// --- Sonuç ------------------------------------------------------------------

$after = $penalty->breakdown($result->schedule);
$violations = $checker->check($result->schedule, $data, requireAll: false);

line();
line(str_repeat('=', 66));
line('SONUÇ');
line(str_repeat('=', 66));

printf("  Başlangıç ceza puanı : %d\n", $result->initialPenalty);
printf("  En iyi ceza puanı    : %d\n", $result->bestPenalty);
printf("  İyileşme             : %d  (%%%.1f)\n", $result->improvement(), $result->improvementPercent());
printf("  İterasyon            : %d\n", $result->iterations);
printf("  Kabul edilen hamle   : %d\n", $result->acceptedMoves);
printf("  Katı kuralla red     : %d\n", $result->rejectedByHard);
printf("  Süre                 : %.2f sn\n", $result->elapsedSeconds);
printf("  Hamle hızı           : %s hamle/sn\n", number_format($result->iterations / max(0.001, $result->elapsedSeconds), 0));

line();
line('  Kural bazında ceza (önce → sonra):');
printf("    E1  aynı gün birden fazla sınav : %7d → %7d   (%d → %d olay)\n", $before['E1'], $after['E1'], $before['counts']['E1'], $after['counts']['E1']);
printf("    E2  art arda saat dilimi        : %7d → %7d   (%d → %d olay)\n", $before['E2'], $after['E2'], $before['counts']['E2'], $after['counts']['E2']);
printf("    E3  aynı gün farklı bina        : %7d → %7d   (%d → %d olay)\n", $before['E3'], $after['E3'], $before['counts']['E3'], $after['counts']['E3']);
printf("    E4  kapasite israfı             : %7d → %7d\n", $before['E4'], $after['E4']);

line();

if (count($violations) === 0) {
    line('  ✓ Bağımsız denetleyici: katı kural ihlali yok.');
} else {
    line('  ✗ Bağımsız denetleyici '.count($violations).' ihlal buldu: '
        .json_encode(HardConstraintChecker::summarize($violations)));
}

// total() ile bildirilen puan birbirini tutmalı — motorun kendi kendini kontrolü.
$recomputed = $penalty->total($result->schedule);

if ($recomputed !== $result->bestPenalty) {
    line("  ✗ Bildirilen puan ({$result->bestPenalty}) ile yeniden hesap ({$recomputed}) uyuşmuyor!");
    exit(1);
}

line('  ✓ Bildirilen puan, çizelgenin sıfırdan hesaplanan puanıyla birebir aynı.');

if (count($built['unplaced']) > 0) {
    line();
    line('  ! '.count($built['unplaced']).' sınav yerleştirilemedi: daha fazla saat dilimi veya derslik gerekiyor.');
}

// --- Gözetmen atama ---------------------------------------------------------

line();
line(str_repeat('=', 66));
line('GÖZETMEN ATAMA');
line(str_repeat('=', 66));

$invigilationData = InvigilationFactory::forSchedule($data, $result->schedule);
$invigilation = (new InvigilationAssigner($invigilationData))->assign();
$invigilationViolations = (new InvigilationChecker)->check($invigilation->assignments, $invigilationData);

printf("  Öğretim üyesi        : %d\n", count($invigilationData->lecturerIds));
printf("  Toplam görev         : %d\n", $invigilation->dutyCount());
printf("  Yük sapması          : %.2f → %.2f  (%%%.1f iyileşme)\n",
    $invigilation->deviationBefore, $invigilation->deviationAfter, $invigilation->improvementPercent());
printf("  Yük aralığı          : en düşük %d, en yüksek %d\n",
    min($invigilation->loads), max($invigilation->loads));
printf("  Dengeleme hamlesi    : %d\n", $invigilation->balanceMoves);
printf("  Süre                 : %.2f sn\n", $invigilation->elapsedSeconds);

if ($invigilation->hasShortage()) {
    printf("  ! %d sınavda gözetmen eksiği var (toplam %d kişi).\n",
        count($invigilation->shortages), array_sum($invigilation->shortages));
}

line();

if (count($invigilationViolations) === 0) {
    line('  ✓ Bağımsız denetleyici: K4 dahil hiçbir gözetmen kuralı bozulmadı.');
} else {
    line('  ✗ Gözetmen atamasında '.count($invigilationViolations).' ihlal: '
        .json_encode(InvigilationChecker::summarize($invigilationViolations)));
}

line();

exit(count($violations) === 0 && count($invigilationViolations) === 0 ? 0 : 1);
