<?php

namespace App\Console\Commands;

use App\Models\Solution;
use App\Support\RedisAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * "Neden çalışmıyor?" sorusunun tek komutluk cevabı.
 *
 * Bu komut bir arıza sonrası yazıldı. Kuyruk işçisi konteyneri açılır
 * açılmaz ölüyordu (imajda phpredis yoktu), arayüzde ise iş sonsuza
 * kadar "kuyrukta bekliyor" görünüyordu. Hiçbir ekran sebebi
 * söylemiyordu; sebep ancak konteyner kayıtlarına bakılarak bulundu.
 *
 * Buradaki kontrollerin ortak özelliği şu: hepsi, gerçekten yaşanmış
 * ya da yaşanması kuvvetle muhtemel bir arızayı isimlendirir. Süs
 * niteliğinde bilgi yoktur — her satırın karşılığı bir hata mesajıdır.
 *
 *   php artisan durum
 */
class StatusCommand extends Command
{
    protected $signature = 'durum';

    protected $description = 'Kurulumun ve çalışan servislerin durumunu tek ekranda gösterir.';

    /** Bulunan sorunlar — komutun çıkış kodunu bunlar belirler. */
    private array $problems = [];

    /** Veritabanına ulaşılabiliyor mu? Sonraki kontrollerin çoğu buna bağlı. */
    private bool $databaseUp = false;

    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>Sınav Programı — sistem durumu</>');

        // Her bölüm ayrı ayrı korunuyor. Arıza teşhis komutunun kendisi
        // arıza anında çökerse hiçbir işe yaramaz: bir kontrolün patlaması
        // diğerlerinin sonucunu göstermeye engel olmamalı.
        $rows = array_merge(
            $this->safely('Veritabanı', fn () => $this->databaseRows()),
            $this->safely('Sürücüler', fn () => $this->driverRows()),
            $this->safely('Kuyruk', fn () => $this->queueRows()),
            $this->safely('Arayüz varlıkları', fn () => $this->assetRows()),
            $this->safely('Veri', fn () => $this->dataRows()),
        );

        $this->line('');
        $this->table(['', 'Kontrol', 'Sonuç'], $rows);

        if ($this->problems === []) {
            $this->info('Her şey yerinde.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->warn(count($this->problems).' sorun var:');

        foreach ($this->problems as $problem) {
            $this->line('  • '.$problem);
        }

        return self::FAILURE;
    }

    /**
     * Bir kontrol bölümünü hatadan yalıtarak çalıştırır.
     *
     * @param  callable():array<int,array{0:string,1:string,2:string}>  $check
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private function safely(string $name, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            $this->problems[] = "{$name} kontrolü çalıştırılamadı: ".$this->short($e->getMessage());

            return [[$this->mark(false), $name, 'kontrol edilemedi']];
        }
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function databaseRows(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->problems[] = 'Veritabanına bağlanılamıyor. `docker compose up -d db` ve `docker compose ps`.';

            return [[$this->mark(false), 'Veritabanı', $this->short($e->getMessage())]];
        }

        $this->databaseUp = true;

        $rows = [[$this->mark(true), 'Veritabanı', config('database.default').' — bağlantı var']];

        // Eksik göç, "tablo yok" hatası olarak çok sonra ortaya çıkar.
        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();

            $rows[] = [$this->mark($pending === 0), 'Göçler', $pending === 0
                ? 'hepsi uygulanmış'
                : "{$pending} göç bekliyor"];

            if ($pending > 0) {
                $this->problems[] = "{$pending} göç uygulanmamış: `php artisan migrate --force`.";
            }
        } catch (Throwable $e) {
            $rows[] = [$this->mark(false), 'Göçler', $this->short($e->getMessage())];
            $this->problems[] = 'Göç durumu okunamadı: `php artisan migrate --force`.';
        }

        return $rows;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function driverRows(): array
    {
        $rows = [
            [$this->mark(true), 'Kuyruk sürücüsü', (string) config('queue.default')],
            [$this->mark(true), 'Önbellek sürücüsü', (string) config('cache.default')],
        ];

        // Yapılandırmada Redis yazıp Redis'in kurulu olmaması, bu projede
        // yaşanan arızanın ta kendisiydi. AppServiceProvider sessizce
        // veritabanına düşüyor; düşüşün olduğunu burada söylemek gerekir,
        // yoksa ".env'de redis yazıyor ama neden database?" sorusu doğar.
        $configuredRedis = in_array('redis', [
            env('QUEUE_CONNECTION'),
            env('CACHE_STORE'),
            env('SESSION_DRIVER'),
        ], true);

        if ($configuredRedis && ! RedisAvailability::usable()) {
            $rows[] = [$this->mark(null), 'Redis', 'yapılandırılmış ama kurulu değil — veritabanına düşüldü'];
            $this->problems[] = 'Redis isteniyor ama phpredis eklentisi yok. '
                .'Ya imajı yenileyin (`docker compose build`) ya da ayarları `database` yapın.';
        }

        return $rows;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function queueRows(): array
    {
        if (! $this->databaseUp || config('queue.default') !== 'database') {
            return [];
        }

        $rows = [];

        $waiting = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        // Kuyrukta bekleyen iş tek başına sorun değil; uzun süredir
        // bekleyen iş sorundur. İşçi ayaktaysa saniyeler içinde alınır.
        $stale = DB::table('jobs')
            ->where('available_at', '<', now()->subMinute()->getTimestamp())
            ->count();

        $rows[] = [$this->mark($stale === 0), 'Kuyruk', $waiting === 0
            ? 'boş'
            : "{$waiting} iş bekliyor".($stale > 0 ? " ({$stale} tanesi bir dakikadan uzun süredir)" : '')];

        if ($stale > 0) {
            $this->problems[] = 'Kuyruktaki iş alınmıyor — işçi çalışmıyor olabilir: '
                .'`docker compose logs worker --tail 20` ve `docker compose up -d worker`.';
        }

        if ($failed > 0) {
            $rows[] = [$this->mark(false), 'Başarısız iş', "{$failed} iş başarısız oldu"];
            $this->problems[] = "{$failed} iş başarısız: `php artisan queue:failed` ile sebebine bakın.";
        }

        return $rows;
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function assetRows(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            $this->problems[] = 'Arayüz varlıkları derlenmemiş: `npm run build`.';

            return [[$this->mark(false), 'Arayüz varlıkları', 'manifest.json yok']];
        }

        // Derlenmiş CSS, Blade dosyalarından eskiyse yeni eklenen Tailwind
        // sınıfları CSS'e girmemiş demektir: düğme görünür ama renksiz,
        // biçimsiz olur. Gözle fark edilmesi zor, sebebi ise hiç belli
        // değil — bu yüzden ayrı bir kontrol.
        $newestBlade = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file) => $file->getMTime())
            ->max() ?? 0;

        $fresh = File::lastModified($manifest) >= $newestBlade;

        if (! $fresh) {
            $this->problems[] = 'Blade dosyaları derlenmiş CSS\'ten yeni. '
                .'Yeni sınıflar eklendiyse biçimsiz görünür: `npm run build`.';
        }

        return [[$this->mark($fresh), 'Arayüz varlıkları', $fresh
            ? 'derlenmiş ve güncel'
            : 'derlenmiş ama Blade dosyalarından eski']];
    }

    /** @return array<int,array{0:string,1:string,2:string}> */
    private function dataRows(): array
    {
        if (! $this->databaseUp) {
            return [];
        }

        $rows = [];

        try {
            $users = DB::table('users')->count();
            $exams = DB::table('exams')->count();
        } catch (Throwable $e) {
            return [[$this->mark(false), 'Veri', $this->short($e->getMessage())]];
        }

        $rows[] = [$this->mark($users > 0), 'Kullanıcı', $users === 0 ? 'hiç kullanıcı yok' : "{$users} kullanıcı"];

        if ($users === 0) {
            $this->problems[] = 'Hiç kullanıcı yok, giriş yapılamaz: '
                .'`php artisan migrate --seed --force` ya da `php artisan kullanici:ekle ...`.';
        }

        $rows[] = [$this->mark($exams > 0), 'Sınav', $exams === 0 ? 'veri aktarılmamış' : "{$exams} sınav"];

        if ($exams === 0) {
            $this->problems[] = 'Sınavı olan ders yok; program üretilemez. Veri aktarın ya da demo veriyi yükleyin.';
        }

        $latest = Solution::withoutGlobalScopes()->orderByDesc('id')->first();

        if ($latest === null) {
            $rows[] = [$this->mark(null), 'Son çözüm', 'henüz üretilmedi'];

            return $rows;
        }

        $stuck = $latest->status === Solution::QUEUED
            && $latest->created_at?->diffInMinutes(now()) >= 1;

        $rows[] = [
            $this->mark($latest->status === Solution::FAILED ? false : ($stuck ? null : true)),
            'Son çözüm',
            "#{$latest->id} {$latest->label} — {$latest->status}"
                .($latest->penalty !== null ? ' (ceza '.number_format($latest->penalty).')' : ''),
        ];

        if ($latest->status === Solution::FAILED) {
            $this->problems[] = 'Son çözüm başarısız: '.$this->short((string) $latest->failure_reason);
        }

        if ($stuck) {
            $this->problems[] = 'Son çözüm bir dakikadan uzun süredir kuyrukta. '
                .'İşçi çalışmıyorsa arayüzdeki "Kuyruğu beklemeden şimdi üret" düğmesi çıkış yolu.';
        }

        return $rows;
    }

    /** true → tamam, false → sorun, null → bilgi. */
    private function mark(?bool $ok): string
    {
        return match ($ok) {
            true => '<fg=green>✓</>',
            false => '<fg=red>✗</>',
            null => '<fg=yellow>!</>',
        };
    }

    private function short(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 90 ? mb_substr($message, 0, 87).'…' : $message;
    }
}
