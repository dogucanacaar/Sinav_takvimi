<?php

namespace Tests\Feature;

use App\Models\Solution;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan durum` — arıza teşhis komutu.
 *
 * Bu komutun değeri, arıza anında çalışmasında. Dolayısıyla testler
 * "sağlıklı kurulumda güzel görünüyor mu"yu değil, bozuk durumları
 * gerçekten isimlendiriyor mu'yu sınar.
 */
class StatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bos_kurulumda_eksikler_isimlendirilir(): void
    {
        $this->artisan('durum')
            ->expectsOutputToContain('Hiç kullanıcı yok')
            ->expectsOutputToContain('Sınavı olan ders yok')
            ->assertExitCode(1);
    }

    /**
     * Bu satır, bütün komutun yazılma sebebi: işçi ölüyken iş kuyrukta
     * birikiyordu ve hiçbir yerde sebebi yazmıyordu.
     */
    public function test_kuyrukta_takilan_is_bildirilir(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'solve',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(5)->getTimestamp(),
            'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);

        $this->artisan('durum')
            ->expectsOutputToContain('Kuyruktaki iş alınmıyor')
            ->assertExitCode(1);
    }

    /** Yeni bırakılmış bir iş, henüz sorun değildir. */
    public function test_yeni_birakilan_is_sorun_sayilmaz(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'solve',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('durum')->doesntExpectOutputToContain('Kuyruktaki iş alınmıyor');
    }

    public function test_basarisiz_cozum_bildirilir(): void
    {
        $tenant = Tenant::create(['name' => 'Test Fakültesi']);
        Tenancy::use($tenant->id);

        Solution::create([
            'tenant_id' => $tenant->id,
            'label' => 'Bozuk çözüm',
            'status' => Solution::FAILED,
            'params' => [],
            'failure_reason' => 'Bu kurumda sınavı olan ders yok.',
        ]);

        $this->artisan('durum')
            ->expectsOutputToContain('Son çözüm başarısız')
            ->assertExitCode(1);
    }

    /**
     * Teşhis komutunun kendisi arıza anında çökerse hiçbir işe yaramaz.
     * Eksik tablo, en olası bozuk kurulum hâli: komut bunu bir satır
     * olarak raporlamalı, istisna fırlatmamalı.
     */
    public function test_eksik_tablo_komutu_cokertmez(): void
    {
        config(['queue.default' => 'database']);

        Schema::drop('jobs');

        $this->artisan('durum')
            ->expectsOutputToContain('Kuyruk kontrolü çalıştırılamadı')
            ->assertExitCode(1);
    }
}
