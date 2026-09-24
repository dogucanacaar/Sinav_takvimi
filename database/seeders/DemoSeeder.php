<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Gerçek veri olmadan sunum yapılabilmesi için gerçekçi boyutta demo verisi.
 *
 * Mimarideki boyut: 120 ders, 2500 öğrenci, öğrenci başına 5–7 ders,
 * 30 derslik, 5 gün × 4 saat dilimi. Bu boyut hem gerçekçi hem de motorun
 * bir dakikanın altında sonuç vermesi için uygundur.
 *
 * Önemli ayrıntı — çakışma yapısı. Öğrenciler derslerini rastgele seçmez.
 * Belirleyici olan bölüm de değildir: 1. sınıf dersiyle 4. sınıf dersi
 * ortak öğrenci taşımaz. Bu yüzden veri (bölüm × sınıf) grupları üzerine
 * kurulur; öğrenci kendi grubunun derslerini alır, üstüne bölümünün
 * seçmeli havuzundan 1-2 ders ekler.
 *
 * Bu detay süs değil: sadece bölüme göre rastgele seçim yapıldığında
 * çakışma grafiği neredeyse tamamlanıyor (ortalama derece 107/119) ve
 * 120 sınav 20 saat dilimine hiçbir şekilde sığmıyor. Sınıf boyutu
 * eklendiğinde ortalama derece 8'e düşüyor ve tüm sınavlar yerleşiyor.
 */
class DemoSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'Bilgisayar Mühendisliği',
        'Elektrik-Elektronik Mühendisliği',
        'Makine Mühendisliği',
        'İnşaat Mühendisliği',
        'Endüstri Mühendisliği',
        'Mimarlık',
    ];

    private const YEARS = 4;

    private const COURSES_PER_COHORT = 5;

    /** Sınıf mevcutları aşağı sınıflarda daha kalabalıktır. */
    private const YEAR_WEIGHTS = [0.35, 0.27, 0.22, 0.16];

    private const ELECTIVES_PER_DEPARTMENT = 5;

    private const STUDENT_COUNT = 2500;

    private const DAYS = 5;

    private const SLOTS_PER_DAY = 4;

    public function run(): void
    {
        // İkinci kez çalıştırılırsa veri çoğalır: ikinci bir kurum, ikinci
        // bir 120 ders, ikinci bir 2500 öğrenci. `migrate --seed` komutunu
        // tekrar çalıştırmak sık yapılan bir şey olduğu için burada durulur.
        if (Tenant::query()->exists()) {
            $this->command?->warn(
                'Veritabanında zaten kurum var; demo verisi yüklenmedi. '
                .'Sıfırdan kurmak için: php artisan migrate:fresh --seed'
            );

            return;
        }

        mt_srand(20260612); // Tekrar üretilebilir demo verisi

        $tenant = Tenant::create(['name' => 'Mühendislik-Mimarlık Fakültesi']);
        Tenancy::use($tenant->id);

        $this->command?->info("Kurum oluşturuldu: {$tenant->name}");

        $buildings = $this->seedBuildings($tenant->id);
        $rooms = $this->seedRooms($tenant->id, $buildings);
        $slots = $this->seedSlots($tenant->id);
        $lecturers = $this->seedLecturers($tenant->id);

        [$cohortCourses, $electivePool] = $this->seedCourses($tenant->id, $lecturers);
        [$studentCount, $enrollments] = $this->seedStudentsAndEnrollments($tenant->id, $cohortCourses, $electivePool);

        $this->seedExams($tenant->id, $enrollments);
        $this->seedUnavailability($lecturers, $slots);
        $this->seedUsers($tenant->id, $lecturers);

        $sizes = array_map(count(...), $enrollments);

        $this->command?->info(sprintf(
            'Demo verisi hazır: %d ders, %d öğrenci, %d kayıt, %d derslik, %d saat dilimi, %d öğretim üyesi.',
            array_sum(array_map(count(...), $cohortCourses)), $studentCount, array_sum($sizes),
            count($rooms), count($slots), count($lecturers),
        ));

        $this->command?->info(sprintf(
            'Sınav büyüklüğü: en küçük %d, ortalama %d, en büyük %d — en büyük derslik %d.',
            min($sizes), (int) round(array_sum($sizes) / count($sizes)), max($sizes), max($rooms),
        ));
    }

    /** @return array<int,int> bina id listesi */
    private function seedBuildings(int $tenantId): array
    {
        $names = ['A Blok', 'B Blok', 'C Blok'];
        $ids = [];

        foreach ($names as $name) {
            $ids[] = DB::table('buildings')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /** @return array<int,int> roomId => kapasite */
    private function seedRooms(int $tenantId, array $buildings): array
    {
        // 30 derslik: küçük seminer salonlarından büyük amfilere.
        // En büyük sınav (seçmeli dersler, ~220 kişi) mutlaka bir dersliğe
        // sığmalı; yoksa o sınav hiçbir yere yerleşemez.
        $plan = array_merge(
            array_fill(0, 12, [40, 70]),    // küçük sınıflar
            array_fill(0, 12, [90, 140]),   // orta sınıflar
            array_fill(0, 6, [200, 320]),   // amfiler
        );

        $rows = [];
        $index = 1;

        foreach ($plan as $i => [$min, $max]) {
            $buildingId = $buildings[$i % count($buildings)];

            $rows[] = [
                'tenant_id' => $tenantId,
                'building_id' => $buildingId,
                'name' => sprintf('D-%03d', $index++),
                'capacity' => mt_rand($min, $max),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('rooms')->insert($rows);

        return DB::table('rooms')->where('tenant_id', $tenantId)->pluck('capacity', 'id')->all();
    }

    /** @return array<int,int> slot id listesi */
    private function seedSlots(int $tenantId): array
    {
        $startTimes = ['09:00:00', '11:00:00', '14:00:00', '16:00:00'];
        $rows = [];

        for ($day = 0; $day < self::DAYS; $day++) {
            $date = now()->setDate(2026, 6, 8)->addDays($day)->toDateString();

            for ($index = 0; $index < self::SLOTS_PER_DAY; $index++) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'day' => $date,
                    'index_in_day' => $index + 1,
                    'starts_at' => $startTimes[$index],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('slots')->insert($rows);

        return DB::table('slots')->where('tenant_id', $tenantId)
            ->orderBy('day')->orderBy('index_in_day')->pluck('id')->all();
    }

    /** @return array<int,int> öğretim üyesi id listesi */
    private function seedLecturers(int $tenantId): array
    {
        $titles = ['Prof. Dr.', 'Doç. Dr.', 'Dr. Öğr. Üyesi', 'Öğr. Gör.', 'Arş. Gör.'];
        $first = ['Ahmet', 'Mehmet', 'Ayşe', 'Fatma', 'Mustafa', 'Zeynep', 'Emre', 'Elif', 'Hakan', 'Merve',
            'Burak', 'Seda', 'Kerem', 'Deniz', 'Onur', 'Ceren', 'Serkan', 'Büşra', 'Tolga', 'Gamze'];
        $last = ['Yılmaz', 'Kaya', 'Demir', 'Çelik', 'Şahin', 'Yıldız', 'Aydın', 'Öztürk', 'Arslan', 'Doğan',
            'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan', 'Şimşek', 'Polat', 'Erdoğan'];

        $rows = [];

        for ($i = 0; $i < 80; $i++) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'name' => $first[mt_rand(0, count($first) - 1)].' '.$last[mt_rand(0, count($last) - 1)],
                'title' => $titles[mt_rand(0, count($titles) - 1)],
                'department' => self::DEPARTMENTS[$i % count(self::DEPARTMENTS)],
                // Geçmiş dönem yükü: gözetmen dağıtımının adil başlamasını engeller,
                // dengeleme adımının işe yaradığını görebilmek için bilerek eşitsiz.
                'past_duty_count' => mt_rand(0, 8),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('lecturers')->insert($rows);

        return DB::table('lecturers')->where('tenant_id', $tenantId)->pluck('id')->all();
    }

    /**
     * Dersler (bölüm × sınıf) gruplarına dağıtılır: 6 bölüm × 4 sınıf ×
     * 5 ders = 120 ders.
     *
     * @return array{0:array<string,int[]>,1:array<int,int[]>} [grup => ders id'leri, bölüm => seçmeli havuzu]
     */
    private function seedCourses(int $tenantId, array $lecturers): array
    {
        $prefixes = ['BLM', 'EEM', 'MAK', 'INS', 'END', 'MIM'];
        $topics = ['Giriş', 'Temelleri', 'Analizi', 'Tasarımı', 'Uygulamaları',
            'İleri Konular', 'Laboratuvarı', 'Projesi', 'Sistemleri', 'Yöntemleri'];

        $rows = [];
        $cohortKeys = [];

        foreach (self::DEPARTMENTS as $dept => $department) {
            for ($year = 1; $year <= self::YEARS; $year++) {
                for ($n = 1; $n <= self::COURSES_PER_COHORT; $n++) {
                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'code' => sprintf('%s%d%02d', $prefixes[$dept], $year, $n),
                        'name' => $department.' — '.$topics[($year * 3 + $n) % count($topics)]." ({$year}. sınıf)",
                        'department' => $department,
                        'lecturer_id' => $lecturers[count($rows) % count($lecturers)],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $cohortKeys[] = "$dept:$year";
                }
            }
        }

        DB::table('courses')->insert($rows);

        $ids = DB::table('courses')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id')->all();

        $cohortCourses = [];

        foreach ($ids as $i => $courseId) {
            $cohortCourses[$cohortKeys[$i]][] = (int) $courseId;
        }

        // Seçmeli havuzu: her bölümün üst sınıf derslerinden beşi, kendi
        // bölümünün diğer sınıflarına da açıktır. Havuz çok dar tutulursa
        // seçmeli dersler şişer ve hiçbir dersliğe sığmaz.
        $electivePool = [];

        foreach (array_keys(self::DEPARTMENTS) as $dept) {
            $upper = array_merge(
                $cohortCourses["$dept:".(self::YEARS - 1)] ?? [],
                $cohortCourses["$dept:".self::YEARS] ?? [],
            );

            shuffle($upper);
            $electivePool[$dept] = array_slice($upper, 0, self::ELECTIVES_PER_DEPARTMENT);
        }

        return [$cohortCourses, $electivePool];
    }

    /**
     * Öğrenciler ve kayıtları birlikte üretilir: bir öğrencinin ders
     * listesi hangi (bölüm × sınıf) grubunda olduğuna bağlıdır.
     *
     * @return array{0:int,1:array<int,int[]>} [öğrenci sayısı, courseId => öğrenci id'leri]
     */
    private function seedStudentsAndEnrollments(int $tenantId, array $cohortCourses, array $electivePool): array
    {
        $studentRows = [];
        $cohortOf = [];   // sıra numarası => "bölüm:sınıf"
        $number = 2026000;

        foreach (array_keys(self::DEPARTMENTS) as $dept) {
            $inDepartment = (int) round(self::STUDENT_COUNT / count(self::DEPARTMENTS));

            for ($year = 1; $year <= self::YEARS; $year++) {
                $cohortSize = (int) round($inDepartment * self::YEAR_WEIGHTS[$year - 1]);

                for ($i = 0; $i < $cohortSize; $i++) {
                    $studentRows[] = [
                        'tenant_id' => $tenantId,
                        'number' => (string) $number++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $cohortOf[] = "$dept:$year";
                }
            }
        }

        foreach (array_chunk($studentRows, 1000) as $chunk) {
            DB::table('students')->insert($chunk);
        }

        $studentIds = DB::table('students')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id')->all();

        $enrollments = [];
        $rows = [];

        foreach ($studentIds as $i => $studentId) {
            $dept = (int) explode(':', $cohortOf[$i])[0];

            foreach ($this->pickCourses($cohortCourses[$cohortOf[$i]], $electivePool[$dept]) as $courseId) {
                $enrollments[$courseId][] = (int) $studentId;
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'student_id' => $studentId,
                    'course_id' => $courseId,
                ];
            }
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('enrollments')->insert($chunk);
        }

        return [count($studentIds), $enrollments];
    }

    /**
     * Bir öğrencinin ders listesi: kendi grubundan 4-5 ders + bölümünün
     * seçmeli havuzundan 1-2 ders = toplam 5-7 ders.
     *
     * @return int[]
     */
    private function pickCourses(array $cohort, array $electives): array
    {
        shuffle($cohort);
        $picked = array_slice($cohort, 0, min(count($cohort), mt_rand(4, 5)));

        $available = array_values(array_diff($electives, $picked));
        shuffle($available);

        return array_merge($picked, array_slice($available, 0, mt_rand(1, 2)));
    }

    private function seedExams(int $tenantId, array $enrollments): void
    {
        $rows = [];

        foreach ($enrollments as $courseId => $students) {
            // Kimsenin almadığı dersin sınavı da olmaz.
            if ($students === []) {
                continue;
            }

            $rows[] = [
                'tenant_id' => $tenantId,
                'course_id' => $courseId,
                'student_count' => count($students),
                'duration_min' => [60, 90, 120][mt_rand(0, 2)],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('exams')->insert($rows);
    }

    /**
     * Demo kullanıcılar — üç rolün farkını ekranda görebilmek için.
     * Parola hepsinde: sinav2026
     */
    private function seedUsers(int $tenantId, array $lecturers): void
    {
        $password = Hash::make('sinav2026');

        User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Fakülte Yöneticisi',
            'email' => 'yonetici@ornek.edu.tr',
            'password' => $password,
            'role' => User::ADMIN,
        ]);

        User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Bölüm Başkanı',
            'email' => 'bolum@ornek.edu.tr',
            'password' => $password,
            'role' => User::DEPARTMENT_HEAD,
        ]);

        User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Öğretim Üyesi',
            'email' => 'hoca@ornek.edu.tr',
            'password' => $password,
            'role' => User::LECTURER,
            'lecturer_id' => $lecturers[0] ?? null,
        ]);

        $this->command?->info('Demo kullanıcılar: yonetici@ / bolum@ / hoca@ornek.edu.tr — parola: sinav2026');
    }

    /**
     * K4 için veri: her öğretim üyesinin müsait olmadığı saat dilimleri.
     * Ortalama iki saat dilimi; hiç kısıt olmazsa gözetmen ataması
     * gereğinden kolay görünür.
     */
    private function seedUnavailability(array $lecturers, array $slots): void
    {
        $rows = [];

        foreach ($lecturers as $lecturerId) {
            $busy = [];
            $count = mt_rand(0, 4);

            while (count($busy) < $count) {
                $busy[$slots[mt_rand(0, count($slots) - 1)]] = true;
            }

            foreach (array_keys($busy) as $slotId) {
                $rows[] = ['lecturer_id' => $lecturerId, 'slot_id' => $slotId];
            }
        }

        if ($rows !== []) {
            DB::table('lecturer_unavailability')->insert($rows);
        }
    }
}
