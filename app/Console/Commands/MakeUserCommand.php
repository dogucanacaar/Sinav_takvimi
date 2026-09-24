<?php

namespace App\Console\Commands;

use App\Models\Lecturer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Kullanıcı oluşturur veya parolasını değiştirir.
 *
 * Bu komut olmadan sisteme girilemeyen bir duruma düşmek mümkün:
 * kayıt olma ekranı bilinçli olarak yok (kullanıcıları fakülte yönetimi
 * tanımlar) ve demo kullanıcılar yalnızca DemoSeeder ile geliyor. Gerçek
 * veriyle çalışan bir kurulumda ilk yöneticiyi açacak bir yol gerekir.
 *
 *   php artisan kullanici:ekle admin@fakulte.edu.tr --ad="Ayşe Yılmaz"
 *   php artisan kullanici:ekle hoca@fakulte.edu.tr --rol=lecturer --hoca=12
 *   php artisan kullanici:ekle admin@fakulte.edu.tr --parola=yeniparola
 */
class MakeUserCommand extends Command
{
    protected $signature = 'kullanici:ekle
        {eposta : Kullanıcının e-posta adresi}
        {--ad= : Ad soyad (boşsa e-postadan türetilir)}
        {--rol=admin : admin | department_head | lecturer}
        {--parola= : Parola (boşsa rastgele üretilir ve ekrana yazılır)}
        {--tenant= : Kurum id (boşsa tek kurum varsa o, yoksa sorulur)}
        {--hoca= : lecturer rolü için lecturers tablosundaki id}';

    protected $description = 'Kullanıcı oluşturur veya var olanın parolasını değiştirir.';

    public function handle(): int
    {
        $roles = [User::ADMIN, User::DEPARTMENT_HEAD, User::LECTURER];
        $role = $this->option('rol');

        if (! in_array($role, $roles, true)) {
            $this->error("Geçersiz rol: {$role}. Seçenekler: ".implode(', ', $roles));

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        Tenancy::use($tenant->id);

        $lecturerId = $this->option('hoca') !== null ? (int) $this->option('hoca') : null;

        if ($lecturerId !== null && ! Lecturer::whereKey($lecturerId)->exists()) {
            $this->error("Öğretim üyesi #{$lecturerId} bu kurumda yok.");

            return self::FAILURE;
        }

        $email = $this->argument('eposta');
        $password = $this->option('parola') ?: str()->password(12);
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $existing->update([
                'password' => Hash::make($password),
                'role' => $role,
                'lecturer_id' => $lecturerId ?? $existing->lecturer_id,
            ]);

            $this->info("Kullanıcı güncellendi: {$email}");
        } else {
            User::create([
                'tenant_id' => $tenant->id,
                'name' => $this->option('ad') ?: str($email)->before('@')->headline()->value(),
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'lecturer_id' => $lecturerId,
            ]);

            $this->info("Kullanıcı oluşturuldu: {$email}");
        }

        $this->table(['Alan', 'Değer'], [
            ['Kurum', $tenant->name],
            ['Rol', $role],
            ['Parola', $password],
        ]);

        if (! $this->option('parola')) {
            $this->warn('Parola rastgele üretildi; bir daha gösterilmeyecek.');
        }

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        if ($this->option('tenant')) {
            return Tenant::find((int) $this->option('tenant'));
        }

        $tenants = Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error('Hiç kurum yok. Önce veri aktarın: php artisan migrate --seed');

            return null;
        }

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        $this->error('Birden fazla kurum var; --tenant ile belirtin:');

        foreach ($tenants as $tenant) {
            $this->line("  {$tenant->id}: {$tenant->name}");
        }

        return null;
    }
}
