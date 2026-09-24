<?php

namespace App\Providers;

use App\Support\RedisAvailability;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Yapılandırma Redis diyor ama Redis yoksa, uygulamayı kırmak
        // yerine veritabanı sürücüsüne düş.
        RedisAvailability::applyFallback();

        // Veri aktarımı bütün kurumun verisini değiştirir; yöneticiye ait.
        Gate::define('import-data', fn ($user) => $user->isAdmin());

        // Ekran gizlemek tek başına yetmez, ama menüde olmayan bir şeyi
        // kullanıcı da aramaz: görünürlük de aynı kapıdan geçer.
        Gate::define('manage-solutions', fn ($user) => $user->isAdmin());
    }
}
