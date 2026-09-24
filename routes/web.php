<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PrintController;
use App\Http\Middleware\RedirectLecturers;
use App\Livewire\ImportWizard;
use App\Livewire\InvigilationTable;
use App\Livewire\ScheduleBoard;
use App\Livewire\SolutionCompare;
use App\Livewire\SolutionLauncher;
use Illuminate\Support\Facades\Route;

Route::get('/giris', [AuthController::class, 'show'])->name('login');
Route::post('/giris', [AuthController::class, 'login']);
Route::post('/cikis', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // Program ekranları öğretim üyesine kapalı; ara katman onu kendi
    // görev listesine yönlendirir (403 yerine doğru sayfa).
    Route::middleware(RedirectLecturers::class)->group(function () {
        Route::get('/', ScheduleBoard::class)->name('schedule');
        Route::get('/cozum/{solution}', ScheduleBoard::class)->name('schedule.show');
    });

    // Yetki kontrolü rota katmanında. Bileşenin mount() metodundan
    // abort() etmek, Livewire'ın istek boyunca değiştirdiği "redirect"
    // bağlamasını geri koyamadan çıkmasına ve aynı süreçteki sonraki
    // yönlendirmelerin bozulmasına yol açıyor.
    Route::get('/aktarim', ImportWizard::class)->middleware('can:import-data')->name('import');
    Route::get('/uret', SolutionLauncher::class)->middleware('can:manage-solutions')->name('solve');
    Route::get('/karsilastir', SolutionCompare::class)->name('compare');
    Route::get('/gozetmenler/{solution?}', InvigilationTable::class)->name('invigilation');

    // Yazdırılabilir çıktılar
    Route::get('/cikti/{solution}/kapi-listesi', [PrintController::class, 'doorList'])->name('print.doors');
    Route::get('/cikti/{solution}/gozetmen-cizelgesi', [PrintController::class, 'invigilators'])->name('print.invigilators');
    Route::get('/cikti/{solution}/derslik-plani', [PrintController::class, 'roomPlan'])->name('print.rooms');
});
