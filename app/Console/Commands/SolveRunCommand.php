<?php

namespace App\Console\Commands;

use App\Models\Solution;
use App\Models\Tenant;
use App\Services\SolveService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Arayüz olmadan çözüm üretir.
 *
 * Geliştirme sırasında en çok kullanılan komut budur: parametre
 * değiştirip sonucun nasıl değiştiğini görmenin en hızlı yolu.
 *
 *   php artisan solve:run
 *   php artisan solve:run --seed=42 --max-iter=200000
 *   php artisan solve:run --queue          (kuyruğa bırakır, beklemez)
 */
class SolveRunCommand extends Command
{
    protected $signature = 'solve:run
        {--tenant= : Kurum id (boşsa ilk kurum)}
        {--seed= : Rastgelelik tohumu — aynı tohum aynı sonucu verir}
        {--max-iter= : En fazla iterasyon}
        {--start-temp= : Başlangıç sıcaklığı}
        {--cooling= : Soğuma katsayısı}
        {--label= : Çözüm etiketi}
        {--queue : Hemen çalıştırmak yerine kuyruğa bırak}';

    protected $description = 'Sınav programı üretir (tavlama benzetimi).';

    public function handle(SolveService $service): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::findOrFail((int) $this->option('tenant'))
            : Tenant::query()->orderBy('id')->first();

        if ($tenant === null) {
            $this->error('Kurum bulunamadı. Önce: php artisan migrate --seed');

            return self::FAILURE;
        }

        Tenancy::use($tenant->id);

        $params = array_filter([
            'seed' => $this->option('seed') !== null ? (int) $this->option('seed') : null,
            'max_iter' => $this->option('max-iter') !== null ? (int) $this->option('max-iter') : null,
            'start_temp' => $this->option('start-temp') !== null ? (float) $this->option('start-temp') : null,
            'cooling' => $this->option('cooling') !== null ? (float) $this->option('cooling') : null,
        ], fn ($v) => $v !== null);

        // --queue verilmediğinde iş kuyruğa hiç bırakılmaz: sadece kayıt
        // açılır ve çözüm burada, bu süreçte üretilir. Aksi hâlde
        // QUEUE_CONNECTION=sync olan bir ortamda aynı çözüm iki kez
        // hesaplanırdı.
        $solution = $this->option('queue')
            ? $service->start($tenant->id, $params, $this->option('label'))
            : $service->create($tenant->id, $params, $this->option('label'));

        $this->info("Çözüm #{$solution->id} açıldı — {$tenant->name}");

        if ($this->option('queue')) {
            $this->line('Kuyruğa bırakıldı. İzlemek için: php artisan queue:work --queue=solve');

            return self::SUCCESS;
        }

        $bar = null;

        $solution = $service->execute($solution, function (array $progress) use (&$bar): void {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($progress['max_iter']);
                $bar->start();
            }

            $bar->setProgress(min($progress['iter'], $progress['max_iter']));
            $bar->setMessage((string) $progress['best']);
        });

        $bar?->finish();
        $this->newLine(2);

        return $this->report($solution);
    }

    private function report(Solution $solution): int
    {
        $stats = $solution->stats ?? [];

        if ($solution->status === Solution::FAILED) {
            $this->error('Çözüm başarısız: '.$solution->failure_reason);

            return self::FAILURE;
        }

        $this->table(['Ölçüt', 'Değer'], [
            ['Durum', $solution->status],
            ['Başlangıç ceza puanı', $stats['initial_penalty'] ?? '-'],
            ['En iyi ceza puanı', $solution->penalty],
            ['İyileşme', ($stats['improvement'] ?? '-').' ('.($stats['improvement_percent'] ?? '-').'%)'],
            ['İterasyon', $stats['iterations'] ?? '-'],
            ['Kabul edilen hamle', $stats['accepted_moves'] ?? '-'],
            ['Katı kuralla reddedilen', $stats['rejected_by_hard'] ?? '-'],
            ['Süre (sn)', $stats['elapsed_seconds'] ?? '-'],
            ['Yerleşemeyen sınav', $stats['unplaced_count'] ?? 0],
            ['Katı ihlal', $solution->hard_violations],
        ]);

        if (isset($stats['breakdown'])) {
            $b = $stats['breakdown'];

            $this->table(['Kural', 'Ceza', 'Adet'], [
                ['E1 — aynı gün birden fazla sınav', $b['E1'], $b['counts']['E1']],
                ['E2 — art arda saat dilimi', $b['E2'], $b['counts']['E2']],
                ['E3 — aynı gün farklı bina', $b['E3'], $b['counts']['E3']],
                ['E4 — kapasite israfı', $b['E4'], '-'],
            ]);
        }

        if (isset($stats['invigilation']) && ($stats['invigilation']['durum'] ?? null) === 'tamam') {
            $i = $stats['invigilation'];

            $this->table(['Gözetmen', 'Değer'], [
                ['Toplam görev', $i['gorev_sayisi']],
                ['Öğretim üyesi', $i['ogretim_uyesi']],
                ['Yük sapması (önce → sonra)', $i['sapma_once'].' → '.$i['sapma_sonra'].'  (%'.$i['iyilesme_yuzde'].')'],
                ['Yük aralığı', $i['en_dusuk_yuk'].' – '.$i['en_yuksek_yuk']],
                ['Dengeleme hamlesi', $i['dengeleme_hamlesi']],
                ['Eksik gözetmen', $i['eksik_gozetmen']],
            ]);

            if ($i['eksik_gozetmen'] > 0) {
                $this->warn($i['eksik_gozetmen'].' gözetmen bulunamadı: o saatlerde yeterli müsait öğretim üyesi yok.');
            }
        } elseif (isset($stats['invigilation'])) {
            $this->warn('Gözetmen ataması yapılamadı: '
                .json_encode($stats['invigilation'], JSON_UNESCAPED_UNICODE));
        }

        if (($stats['unplaced_count'] ?? 0) > 0) {
            $this->warn(
                $stats['unplaced_count'].' sınav yerleştirilemedi. '
                .'Daha fazla saat dilimi veya derslik gerekiyor.'
            );
        }

        return self::SUCCESS;
    }
}
