<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * İstek başına hangi kurumda olduğumuzu belirler.
 *
 * Bu olmadan global scope çalışmaz ve sorgular tüm kurumların verisini
 * getirir. Ekranı gizlemek yetmez — sorgunun kendisi başka kurumun
 * verisini hiç görmemelidir.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user?->tenant_id !== null) {
            Tenancy::use($user->tenant_id);

            return $next($request);
        }

        // Kullanıcı yoksa (tek kurumlu kurulum, ilk açılış) ve sistemde
        // tek kurum varsa ona düşülür. Birden fazla kurum varsa hiçbirine
        // düşülmez: yanlış kuruma bakmaktansa hiç bakmamak yeğdir.
        Tenancy::forget();

        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() === 1) {
            Tenancy::use($tenants->first()->id);
        }

        return $next($request);
    }
}
