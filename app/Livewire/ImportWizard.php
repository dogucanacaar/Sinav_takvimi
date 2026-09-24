<?php

namespace App\Livewire;

use App\Import\ImportParser;
use App\Import\ImportTemplate;
use App\Import\Reader\ReaderFactory;
use App\Models\Tenant;
use App\Services\ImportService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Veri aktarma ekranı.
 *
 * Üç adım: dosyayı yükle → raporu gör → onayla.
 *
 * Ortadaki adım atlanmaz. Aktarım kurumun bütün verisini değiştirdiği
 * için kullanıcı, yazılacak şeyi yazılmadan önce görmelidir. Doğrulama
 * ile yazma işleminin ayrı olması (ImportParser / ImportService) tam da
 * bunu mümkün kılar.
 *
 * Sayfaya erişim rotadaki `can:import-data` ara katmanıyla kapatılır;
 * apply() ayrıca kendi kontrolünü yapar. Yetki kontrolü bilinçli olarak
 * mount() içinde değil: bileşenin mount'undan abort() etmek Livewire'ın
 * istek boyunca değiştirdiği kap bağlamalarını geri koymasını engelliyor.
 */
#[Layout('components.layouts.app')]
class ImportWizard extends Component
{
    use WithFileUploads;

    public $file;

    public bool $replace = true;

    public ?array $summary = null;

    // Dikkat: $errors adı Livewire'da doğrulama hatası torbasına ayrılmıştır;
    // satır hataları için ayrı bir ad kullanılır.

    /** @var array<int,array> */
    public array $rowErrors = [];

    /** @var array<int,array> */
    public array $rowWarnings = [];

    // Doğrulanan dosyanın yolu BİLİNÇLİ olarak public bir özellik değil:
    // Livewire'daki her public özellik istemciden değiştirilebilir ve
    // sunucuda dosya yolu olarak kullanılan bir değer, istemcinin
    // seçtiği herhangi bir dosyayı okutabilirdi. Yol her seferinde
    // Livewire'ın imzalı geçici yüklemesinden ($this->file) türetilir.
    public bool $validated = false;

    public ?string $result = null;

    public ?string $failure = null;

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xlsm,csv,txt|max:20480',
        ];
    }

    /** Dosyayı doğrula — henüz hiçbir şey yazılmaz. */
    public function validateFile(): void
    {
        $this->validate();
        $this->reset(['summary', 'rowErrors', 'rowWarnings', 'result', 'failure', 'validated']);

        try {
            $plan = (new ImportParser)->parse(ReaderFactory::open($this->file->getRealPath()));
        } catch (\Throwable $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->validated = true;

        $this->summary = $plan->summary();
        $this->rowErrors = array_map(fn ($e) => $e->toArray(), $plan->errorsOnly());
        $this->rowWarnings = array_map(fn ($e) => $e->toArray(), $plan->warningsOnly());
    }

    /** Onaydan sonra veritabanına yaz. */
    public function apply(ImportService $service): void
    {
        abort_unless(Gate::allows('import-data'), 403);

        if (! $this->validated || $this->file === null || $this->rowErrors !== []) {
            $this->failure = 'Hatalar düzeltilmeden aktarım yapılamaz.';

            return;
        }

        try {
            // Dosya onay adımında yeniden okunur: araya geçen sürede
            // değişmiş olabilir ve yazılacak şey, gösterilen rapora ait olmalı.
            $plan = (new ImportParser)->parse(ReaderFactory::open($this->file->getRealPath()));

            if ($plan->hasErrors()) {
                $this->failure = 'Dosya bu arada değişmiş görünüyor; lütfen yeniden doğrulayın.';

                return;
            }

            $tenant = $this->resolveTenant();
            $written = $service->apply($tenant, $plan, replace: $this->replace);

            $this->result = collect($written)
                ->map(fn ($count, $table) => "{$table}: {$count}")
                ->implode(' · ');
        } catch (\Throwable $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function downloadTemplate()
    {
        $path = storage_path('app/sinav-programi-sablon.xlsx');
        ImportTemplate::write($path);

        return response()->download($path)->deleteFileAfterSend();
    }

    private function resolveTenant(): Tenant
    {
        $tenantId = auth()->user()?->tenant_id;

        return $tenantId !== null
            ? Tenant::findOrFail($tenantId)
            : Tenant::create(['name' => 'İçe aktarılan kurum']);
    }

    public function render()
    {
        return view('livewire.import-wizard', [
            'expected' => ImportTemplate::describe(),
        ]);
    }
}
