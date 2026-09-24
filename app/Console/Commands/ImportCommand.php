<?php

namespace App\Console\Commands;

use App\Import\ImportParser;
use App\Import\ImportTemplate;
use App\Import\Reader\ReaderFactory;
use App\Import\RowError;
use App\Models\Tenant;
use App\Services\ImportService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Elektronik tablodan veri aktarır.
 *
 *   php artisan import:file veriler.xlsx --kurum="Mühendislik Fakültesi"
 *   php artisan import:file veriler.xlsx --dogrula     (yazmaz, sadece rapor)
 *   php artisan import:file sablon.xlsx --sablon       (boş şablon üretir)
 */
class ImportCommand extends Command
{
    protected $signature = 'import:file
        {dosya : .xlsx dosyası, .csv dosyası veya .csv klasörü}
        {--tenant= : Mevcut kurum id}
        {--kurum= : Yeni kurum adı (tenant verilmezse)}
        {--dogrula : Sadece doğrula, veritabanına yazma}
        {--ekle : Mevcut veriyi silme, üstüne ekle}
        {--sablon : Verilen yola doldurulmuş bir örnek şablon yaz ve çık}';

    protected $description = 'Ders, öğrenci, derslik ve saat dilimi verisini içe aktarır.';

    public function handle(ImportService $service): int
    {
        $path = $this->argument('dosya');

        if ($this->option('sablon')) {
            ImportTemplate::write($path);
            $this->info("Şablon oluşturuldu: {$path}");

            return self::SUCCESS;
        }

        try {
            $reader = ReaderFactory::open($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Sayfalar: '.implode(', ', $reader->sheets()));

        $plan = (new ImportParser)->parse($reader);

        $this->newLine();
        $this->table(
            ['Ne', 'Adet'],
            array_map(fn ($k, $v) => [$k, $v], array_keys($plan->summary()), array_values($plan->summary())),
        );

        $this->report($plan->errorsOnly(), 'HATA', 'error');
        $this->report($plan->warningsOnly(), 'UYARI', 'warn');

        if ($plan->hasErrors()) {
            $this->newLine();
            $this->error('Hatalar düzeltilmeden aktarım yapılmaz. Hiçbir şey yazılmadı.');

            return self::FAILURE;
        }

        if ($this->option('dogrula')) {
            $this->info('Doğrulama tamam. (--dogrula verildiği için yazılmadı.)');

            return self::SUCCESS;
        }

        $tenant = $this->resolveTenant();
        Tenancy::use($tenant->id);

        $written = $service->apply($tenant, $plan, replace: ! $this->option('ekle'));

        $this->newLine();
        $this->info("Aktarım tamam — {$tenant->name}");
        $this->table(
            ['Tablo', 'Yazılan'],
            array_map(fn ($k, $v) => [$k, $v], array_keys($written), array_values($written)),
        );

        return self::SUCCESS;
    }

    private function resolveTenant(): Tenant
    {
        if ($this->option('tenant')) {
            return Tenant::findOrFail((int) $this->option('tenant'));
        }

        $name = $this->option('kurum') ?: 'İçe aktarılan kurum';

        return Tenant::create(['name' => $name]);
    }

    /** @param RowError[] $errors */
    private function report(array $errors, string $heading, string $style): void
    {
        if ($errors === []) {
            return;
        }

        $count = count($errors);

        $this->newLine();
        $this->{$style}("{$heading} ({$count})");

        // Uzun raporlar terminalde okunmaz hâle gelir; ilk 30 satır yeter,
        // gerisi için arayüzdeki tam rapor var.
        foreach (array_slice($errors, 0, 30) as $error) {
            $this->line('  '.$error);
        }

        if ($count > 30) {
            $this->line('  ... ve '.($count - 30).' satır daha.');
        }
    }
}
