<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sisteme girilemeyen duruma düşmemek.
 *
 * Kayıt olma ekranı bilinçli olarak yok; kullanıcıları yönetim tanımlıyor.
 * O yüzden ilk yöneticiyi açacak ve unutulan parolayı değiştirecek bir
 * yolun her zaman çalışıyor olması gerekiyor.
 */
class UserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_kullanici_olusturulur(): void
    {
        $tenant = Tenant::create(['name' => 'Test Fakültesi']);

        $this->artisan('kullanici:ekle', [
            'eposta' => 'yonetici@ornek.edu.tr',
            '--ad' => 'Ayşe Yılmaz',
            '--parola' => 'gizli-parola',
        ])->assertSuccessful();

        $user = User::where('email', 'yonetici@ornek.edu.tr')->firstOrFail();

        $this->assertSame('Ayşe Yılmaz', $user->name);
        $this->assertSame(User::ADMIN, $user->role);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertTrue(Hash::check('gizli-parola', $user->password));
    }

    public function test_var_olan_kullanicinin_parolasi_degistirilir(): void
    {
        $tenant = Tenant::create(['name' => 'Test Fakültesi']);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Eski',
            'email' => 'yonetici@ornek.edu.tr',
            'password' => 'eski-parola',
            'role' => User::ADMIN,
        ]);

        $this->artisan('kullanici:ekle', [
            'eposta' => 'yonetici@ornek.edu.tr',
            '--parola' => 'yeni-parola',
        ])->assertSuccessful();

        $this->assertSame(1, User::count(), 'İkinci bir kullanıcı oluşmamalı');
        $this->assertTrue(Hash::check('yeni-parola', User::first()->password));
    }

    public function test_gecersiz_rol_reddedilir(): void
    {
        Tenant::create(['name' => 'Test Fakültesi']);

        $this->artisan('kullanici:ekle', [
            'eposta' => 'x@ornek.edu.tr',
            '--rol' => 'kral',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_kurum_yoksa_anlamli_hata_verir(): void
    {
        $this->artisan('kullanici:ekle', ['eposta' => 'x@ornek.edu.tr'])
            ->expectsOutputToContain('Hiç kurum yok')
            ->assertFailed();
    }

    public function test_giris_ekrani_kullanici_yokken_uyarir(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Henüz hiç kullanıcı tanımlı değil')
            ->assertSee('kullanici:ekle');
    }

    public function test_kullanici_varken_uyari_gosterilmez(): void
    {
        $tenant = Tenant::create(['name' => 'Test Fakültesi']);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Yönetici',
            'email' => 'y@ornek.edu.tr',
            'password' => 'parola',
            'role' => User::ADMIN,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Henüz hiç kullanıcı tanımlı değil');
    }

    public function test_demo_veri_iki_kez_yuklenmez(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, Tenant::count(), 'İkinci çalıştırma veriyi çoğaltmamalı');
        $this->assertSame(120, DB::table('courses')->count());
        $this->assertSame(3, User::count());
    }

    public function test_demo_kullanicilarla_giris_yapilir(): void
    {
        $this->seed(DemoSeeder::class);

        $this->post(route('login'), [
            'email' => 'yonetici@ornek.edu.tr',
            'password' => 'sinav2026',
        ])->assertRedirect(route('schedule'));

        $this->assertAuthenticated();
    }
}
