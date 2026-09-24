<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Öğretim üyesini program ekranından kendi görev listesine yönlendirir.
 *
 * Yönlendirme bilinçli olarak Livewire bileşeninin dışında, ara katmanda
 * yapılıyor. Bileşenin mount() metodundan yönlendirmek iki şekilde de
 * tuzaklı: `return redirect()` Livewire'ın akıcı Redirector'ünü döndürüp
 * 500 üretir, `$this->redirect()` ise tam sayfa render sırasında
 * Livewire'ın kendi yönlendirme akışına düşer. Ara katman hem daha
 * basit hem de rota seviyesinde okunur.
 */
class RedirectLecturers
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user !== null && ! $user->canSeeWholeSchedule()) {
            return redirect()->route('invigilation');
        }

        return $next($request);
    }
}
