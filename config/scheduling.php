<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Esnek kural ağırlıkları (E1–E4)
    |--------------------------------------------------------------------------
    |
    | Bu değerler ceza puanını belirler. Kod değiştirmeden .env üzerinden
    | ayarlanabilir. Ağırlığı yükseltmek, motorun o ihlalden daha çok
    | kaçınmasını sağlar.
    |
    | E1: Bir öğrenciye aynı gün birden fazla sınav düşmesi
    | E2: Bir öğrenciye art arda iki saat diliminde sınav düşmesi
    | E3: Öğrencinin aynı gün farklı binalarda sınava girmesi
    | E4: Dersliğin boş kalan kapasitesinin çok olması (israf)
    |
    */

    'weights' => [
        'E1' => (int) env('SCHED_W_E1', 10),
        'E2' => (int) env('SCHED_W_E2', 25),
        'E3' => (int) env('SCHED_W_E3', 15),
        'E4' => (int) env('SCHED_W_E4', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tavlama benzetimi parametreleri
    |--------------------------------------------------------------------------
    |
    | seed: null ise her çalıştırma farklı sonuç verir. Sabit bir tamsayı
    | verilirse aynı girdi için aynı çıktı üretilir — iki çözümü
    | karşılaştırabilmek için şarttır.
    |
    */

    'annealing' => [
        'start_temp' => (float) env('SCHED_START_TEMP', 100),
        'min_temp' => (float) env('SCHED_MIN_TEMP', 0.01),
        'cooling' => (float) env('SCHED_COOLING', 0.9995),
        'max_iter' => (int) env('SCHED_MAX_ITER', 500000),
        'seed' => env('SCHED_SEED') !== null ? (int) env('SCHED_SEED') : null,

        // Hamle seçimi: toplam 100 üzerinden dağılım
        'move_share' => (int) env('SCHED_MOVE_SHARE', 70),   // MoveExam
        'swap_share' => (int) env('SCHED_SWAP_SHARE', 30),   // SwapExams

        // Kaç iterasyonda bir ilerleme bilgisi yazılsın
        'progress_every' => (int) env('SCHED_PROGRESS_EVERY', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gözetmen atama
    |--------------------------------------------------------------------------
    */

    'invigilation' => [
        // Kaç öğrenciye bir gözetmen düşer
        'students_per_invigilator' => (int) env('SCHED_STUDENTS_PER_INVIGILATOR', 40),
        'min_per_exam' => (int) env('SCHED_MIN_INVIGILATORS', 1),
        'balance_iterations' => (int) env('SCHED_BALANCE_ITERATIONS', 200),
    ],

];
