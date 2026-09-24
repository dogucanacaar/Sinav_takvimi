<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rollerin gerçekten ayrıldığını doğrular.
 *
 * Menüde bir bağlantıyı gizlemek yetmez: adres çubuğuna yolu yazan biri
 * için de kapalı olmalı. Bu yüzden testler doğrudan URL'e gidiyor.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Fakültesi']);
    }

    private function user(string $role): User
    {
        $lecturerId = null;

        if ($role === User::LECTURER) {
            $lecturerId = DB::table('lecturers')->insertGetId([
                'tenant_id' => $this->tenant->id, 'name' => 'Hoca', 'title' => null,
                'department' => null, 'past_duty_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kullanıcı '.$role,
            'email' => $role.'@ornek.edu.tr',
            'password' => 'parola-123',
            'role' => $role,
            'lecturer_id' => $lecturerId,
        ]);
    }

    public function test_giris_yapmadan_program_goruntulenemez(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/aktarim')->assertRedirect(route('login'));
        $this->get('/uret')->assertRedirect(route('login'));
    }

    public function test_giris_sayfasi_acilir(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Giriş yap');
    }

    public function test_dogru_bilgiyle_giris_yapilir(): void
    {
        $this->user(User::ADMIN);

        $this->post(route('login'), [
            'email' => 'admin@ornek.edu.tr',
            'password' => 'parola-123',
        ])->assertRedirect(route('schedule'));

        $this->assertAuthenticated();
    }

    public function test_yanlis_parola_reddedilir(): void
    {
        $this->user(User::ADMIN);

        $this->post(route('login'), [
            'email' => 'admin@ornek.edu.tr',
            'password' => 'yanlis',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_yonetici_her_ekrani_acabilir(): void
    {
        $admin = $this->user(User::ADMIN);

        $this->actingAs($admin)->get('/')->assertOk();
        $this->actingAs($admin)->get('/aktarim')->assertOk();
        $this->actingAs($admin)->get('/uret')->assertOk();
        $this->actingAs($admin)->get('/karsilastir')->assertOk();
        $this->actingAs($admin)->get('/gozetmenler')->assertOk();
    }

    public function test_bolum_baskani_programi_gorur_ama_uretemez(): void
    {
        $head = $this->user(User::DEPARTMENT_HEAD);

        $this->actingAs($head)->get('/')->assertOk();
        $this->actingAs($head)->get('/karsilastir')->assertOk();

        $this->actingAs($head)->get('/uret')->assertForbidden();
        $this->actingAs($head)->get('/aktarim')->assertForbidden();
    }

    public function test_ogretim_uyesi_yalnizca_kendi_gorevlerini_gorur(): void
    {
        $lecturer = $this->user(User::LECTURER);

        // Program ekranı yerine gözetmen ekranına yönlendirilir.
        $this->actingAs($lecturer)->get('/')->assertRedirect(route('invigilation'));
        $this->actingAs($lecturer)->get('/gozetmenler')->assertOk();

        $this->actingAs($lecturer)->get('/uret')->assertForbidden();
        $this->actingAs($lecturer)->get('/aktarim')->assertForbidden();
    }

    public function test_baska_kurumun_cozumune_erisilemez(): void
    {
        $other = Tenant::create(['name' => 'Başka Fakülte']);

        $solution = DB::table('solutions')->insertGetId([
            'tenant_id' => $other->id,
            'label' => 'Başka kurumun çözümü',
            'status' => 'completed',
            'params' => json_encode([]),
            'penalty' => 100,
            'hard_violations' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->user(User::ADMIN);

        // Global scope başka kurumun kaydını zaten getirmez: 404.
        $this->actingAs($admin)->get("/cozum/{$solution}")->assertNotFound();
    }

    public function test_cikis_oturumu_kapatir(): void
    {
        $admin = $this->user(User::ADMIN);

        $this->actingAs($admin)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
