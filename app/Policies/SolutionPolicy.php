<?php

namespace App\Policies;

use App\Models\Solution;
use App\Models\User;

/**
 * Çözüm kayıtlarına erişim.
 *
 * Kural basit: programı üretmek yönetim işidir, görmek ise bölüm
 * başkanının da hakkıdır. Öğretim üyesi programın tamamını değil, kendi
 * görevlerini görür — o ekran ayrı ve kendi kontrolünü yapar.
 *
 * Aynı kurum kontrolü ayrıca yapılır: global scope zaten başka kurumun
 * kaydını getirmez, ama id ile doğrudan erişim denemesine karşı burada
 * da bakılır. İki kat kontrol, birinin unutulmasına karşı sigortadır.
 */
class SolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canSeeWholeSchedule();
    }

    public function view(User $user, Solution $solution): bool
    {
        return $user->canSeeWholeSchedule() && $this->sameTenant($user, $solution);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Solution $solution): bool
    {
        return $user->isAdmin() && $this->sameTenant($user, $solution);
    }

    /** Gözetmen çizelgesini herkes kendi payına görebilir. */
    public function viewInvigilation(User $user, Solution $solution): bool
    {
        return $this->sameTenant($user, $solution);
    }

    private function sameTenant(User $user, Solution $solution): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $solution->tenant_id;
    }
}
