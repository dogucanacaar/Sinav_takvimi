<?php

namespace App\Jobs;

use App\Models\Solution;
use App\Services\SolveService;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Çözüm üretimi ayrı bir işçi süreçte çalışır.
 *
 * Nedeni basit: arama dakikalarca sürebilir; bir HTTP isteği bu kadar
 * bekleyemez. Kullanıcı "üret" der, kayıt açılır, sayfa hemen döner;
 * ilerleme Redis üzerinden izlenir.
 *
 * tries = 1: başarısız bir çözüm tekrar denenmemelidir. Aynı veriyle
 * aynı hata tekrar edecektir ve yarım yazılmış kayıtlar bırakır.
 */
class SolveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $solutionId,
        public readonly int $tenantId,
    ) {}

    public function handle(SolveService $service): void
    {
        // Kuyruk işçisinde HTTP isteği yoktur; tenant elle set edilmelidir,
        // yoksa global scope hangi kurumda olduğumuzu bilemez.
        Tenancy::use($this->tenantId);

        $solution = Solution::findOrFail($this->solutionId);

        $service->execute($solution);
    }

    public function failed(\Throwable $e): void
    {
        Tenancy::use($this->tenantId);

        Solution::where('id', $this->solutionId)->update([
            'status' => Solution::FAILED,
            'failure_reason' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
