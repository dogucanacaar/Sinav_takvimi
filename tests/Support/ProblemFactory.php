<?php

namespace Tests\Support;

use App\Solver\ConflictGraph;
use App\Solver\ProblemData;

/**
 * Testler için elle kurulmuş, veritabanı gerektirmeyen problem örnekleri.
 *
 * Motorun Laravel'den bağımsız olmasının karşılığı burada alınır: tüm
 * motor testleri milisaniyeler içinde, hiçbir göç çalıştırmadan koşar.
 */
final class ProblemFactory
{
    /**
     * Küçük ve elle takip edilebilir bir problem.
     *
     * Derslikler : r1=30 (bina 1), r2=50 (bina 1), r3=30 (bina 2), r4=100 (bina 2)
     * Saatler    : 1-2-3 → 1. gün,  4-5-6 → 2. gün
     * Sınavlar   : 1..5
     *
     * Öğrenciler:
     *   101 → sınav 1, 2      (1 ve 2 çakışır)
     *   102 → sınav 2, 3      (2 ve 3 çakışır)
     *   103 → sınav 1, 3      (1 ve 3 çakışır)
     *   104 → sınav 4         (4 kimseyle çakışmaz)
     *   105 → sınav 5         (5 kimseyle çakışmaz)
     */
    public static function small(): ProblemData
    {
        $examStudents = [
            1 => [101, 103],
            2 => [101, 102],
            3 => [102, 103],
            4 => [104],
            5 => [105],
        ];

        return ProblemData::build(
            examIds: [1, 2, 3, 4, 5],
            examSize: [1 => 2, 2 => 2, 3 => 2, 4 => 1, 5 => 1],
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: [1, 2, 3, 4, 5, 6],
            slotMeta: [
                1 => ['day' => '2026-06-12', 'index' => 1],
                2 => ['day' => '2026-06-12', 'index' => 2],
                3 => ['day' => '2026-06-12', 'index' => 3],
                4 => ['day' => '2026-06-13', 'index' => 1],
                5 => ['day' => '2026-06-13', 'index' => 2],
                6 => ['day' => '2026-06-13', 'index' => 3],
            ],
            roomCapacity: [1 => 30, 2 => 50, 3 => 30, 4 => 100],
            roomBuilding: [1 => 1, 2 => 1, 3 => 2, 4 => 2],
        );
    }

    /**
     * DemoSeeder ile aynı boyut ve aynı çakışma yapısındaki problem —
     * ama veritabanına hiç dokunmadan, tamamen bellekte.
     *
     * Motorun gerçek boyutta ne yaptığını görmek için kullanılır
     * (tools/demo-solve.php).
     *
     * Çakışma yapısı burada kritiktir ve ilk denemede yanlış kurulmuştu:
     * öğrenciler derslerini sadece bölüm içinden rastgele seçerse grafik
     * neredeyse tamamlanır (ortalama derece 107/119), 20 saat dilimine
     * hiçbir çizelge sığmaz. Gerçek hayatta bölüm tek başına belirleyici
     * değildir — 1. sınıf dersiyle 4. sınıf dersi ortak öğrenci taşımaz.
     *
     * Doğru model: (bölüm × sınıf) = sınıf grubu. Öğrenci kendi grubunun
     * derslerini alır, üstüne kendi bölümünün seçmeli havuzundan 1–2 ders
     * ekler. Böylece çakışma bölüm içinde yoğun, gruplar arasında seyrek,
     * bölümler arasında ise neredeyse yok olur — gerçek fakültedeki gibi.
     */
    public static function facultyScale(
        int $seed = 20260612,
        int $courseCount = 120,
        int $studentCount = 2500,
        int $departments = 6,
        int $years = 4,
        int $days = 5,
        int $slotsPerDay = 4,
        int $roomCount = 30,
        int $buildingCount = 3,
    ): ProblemData {
        mt_srand($seed);

        [$cohortCourses, $electivePool] = self::courseStructure($courseCount, $departments, $years);

        $examStudents = array_fill_keys(range(1, $courseCount), []);

        // Sınıf mevcutları aşağı sınıflarda daha kalabalıktır.
        $yearWeights = [0.35, 0.27, 0.22, 0.16];
        $studentId = 10000;

        for ($dept = 0; $dept < $departments; $dept++) {
            $inDepartment = (int) round($studentCount / $departments);

            for ($year = 0; $year < $years; $year++) {
                $cohortSize = (int) round($inDepartment * ($yearWeights[$year] ?? 1 / $years));

                for ($i = 0; $i < $cohortSize; $i++) {
                    $studentId++;

                    foreach (self::pickCourses($cohortCourses["$dept:$year"], $electivePool[$dept]) as $courseId) {
                        $examStudents[$courseId][] = $studentId;
                    }
                }
            }
        }

        $examIds = [];
        $examSize = [];

        foreach ($examStudents as $courseId => $students) {
            // Kimsenin almadığı dersin sınavı da olmaz.
            if ($students === []) {
                continue;
            }

            $examIds[] = $courseId;
            $examSize[$courseId] = count($students);
        }

        $examStudents = array_intersect_key($examStudents, array_flip($examIds));

        [$slotIds, $slotMeta] = self::slots($days, $slotsPerDay);
        [$roomCapacity, $roomBuilding] = self::rooms($roomCount, $buildingCount);

        return ProblemData::build(
            examIds: $examIds,
            examSize: $examSize,
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: $slotIds,
            slotMeta: $slotMeta,
            roomCapacity: $roomCapacity,
            roomBuilding: $roomBuilding,
        );
    }

    /**
     * Dersleri (bölüm × sınıf) gruplarına dağıtır ve her bölüm için bir
     * seçmeli havuzu ayırır.
     *
     * @return array{0:array<string,int[]>,1:array<int,int[]>}
     */
    private static function courseStructure(int $courseCount, int $departments, int $years): array
    {
        $cohortCourses = [];
        $perCohort = max(1, intdiv($courseCount, $departments * $years));
        $courseId = 1;

        for ($dept = 0; $dept < $departments; $dept++) {
            for ($year = 0; $year < $years; $year++) {
                $cohortCourses["$dept:$year"] = range($courseId, $courseId + $perCohort - 1);
                $courseId += $perCohort;
            }
        }

        // Seçmeli havuzu: her bölümün üst sınıf derslerinden beşi, kendi
        // bölümünün diğer sınıflarına da açıktır. Havuz çok darsa seçmeli
        // dersler şişer ve hiçbir dersliğe sığmaz.
        $electivePool = [];

        for ($dept = 0; $dept < $departments; $dept++) {
            $upper = array_merge(
                $cohortCourses["$dept:".($years - 2)] ?? [],
                $cohortCourses["$dept:".($years - 1)] ?? [],
            );

            shuffle($upper);
            $electivePool[$dept] = array_slice($upper, 0, 5);
        }

        return [$cohortCourses, $electivePool];
    }

    /**
     * Bir öğrencinin ders listesi: kendi grubundan 4–5 ders + bölüm
     * seçmeli havuzundan 1–2 ders = toplam 5–7 ders.
     *
     * @return int[]
     */
    private static function pickCourses(array $cohort, array $electives): array
    {
        shuffle($cohort);
        $picked = array_slice($cohort, 0, min(count($cohort), mt_rand(4, 5)));

        $available = array_values(array_diff($electives, $picked));
        shuffle($available);

        return array_merge($picked, array_slice($available, 0, mt_rand(1, 2)));
    }

    /** @return array{0:int[],1:array<int,array{day:string,index:int}>} */
    private static function slots(int $days, int $slotsPerDay): array
    {
        $slotIds = [];
        $slotMeta = [];
        $slotId = 1;

        for ($day = 0; $day < $days; $day++) {
            for ($index = 1; $index <= $slotsPerDay; $index++) {
                $slotIds[] = $slotId;
                $slotMeta[$slotId] = ['day' => sprintf('2026-06-%02d', 8 + $day), 'index' => $index];
                $slotId++;
            }
        }

        return [$slotIds, $slotMeta];
    }

    /**
     * DemoSeeder ile aynı derslik dağılımı: küçük sınıflar, orta sınıflar
     * ve amfiler.
     *
     * @return array{0:array<int,int>,1:array<int,int>}
     */
    private static function rooms(int $roomCount, int $buildingCount): array
    {
        $small = (int) round($roomCount * 0.4);
        $medium = (int) round($roomCount * 0.4);

        $plan = array_merge(
            array_fill(0, $small, [40, 70]),
            array_fill(0, $medium, [90, 140]),
            array_fill(0, max(1, $roomCount - $small - $medium), [200, 320]),
        );

        $roomCapacity = [];
        $roomBuilding = [];

        foreach ($plan as $i => [$min, $max]) {
            $roomCapacity[$i + 1] = mt_rand($min, $max);
            $roomBuilding[$i + 1] = 1 + ($i % $buildingCount);
        }

        return [$roomCapacity, $roomBuilding];
    }

    /**
     * Rastgele ama tekrar üretilebilir bir problem.
     *
     * delta() testinin işe yaraması için çizelgenin gerçekçi biçimde
     * karmaşık olması gerekir: az sayıda sınavla hiçbir şey çakışmaz ve
     * hatalı bir delta hesabı fark edilmeden geçer.
     */
    public static function random(
        int $seed = 1,
        int $examCount = 40,
        int $studentCount = 400,
        int $coursesPerStudent = 5,
        int $days = 4,
        int $slotsPerDay = 4,
        int $roomCount = 12,
    ): ProblemData {
        mt_srand($seed);

        $examIds = range(1, $examCount);

        // Öğrenci–sınav kayıtları
        $examStudents = array_fill_keys($examIds, []);

        for ($studentId = 1000; $studentId < 1000 + $studentCount; $studentId++) {
            $picked = [];

            while (count($picked) < $coursesPerStudent) {
                $picked[$examIds[mt_rand(0, $examCount - 1)]] = true;
            }

            foreach (array_keys($picked) as $examId) {
                $examStudents[$examId][] = $studentId;
            }
        }

        $examSize = [];

        foreach ($examStudents as $examId => $students) {
            $examSize[$examId] = count($students);
        }

        // Saat dilimleri
        $slotIds = [];
        $slotMeta = [];
        $slotId = 1;

        for ($day = 1; $day <= $days; $day++) {
            for ($index = 1; $index <= $slotsPerDay; $index++) {
                $slotIds[] = $slotId;
                $slotMeta[$slotId] = [
                    'day' => sprintf('2026-06-%02d', 9 + $day),
                    'index' => $index,
                ];
                $slotId++;
            }
        }

        // Derslikler — en büyük sınav mutlaka sığsın diye en az bir büyük derslik
        $maxSize = max($examSize);
        $roomCapacity = [];
        $roomBuilding = [];

        for ($roomId = 1; $roomId <= $roomCount; $roomId++) {
            $roomCapacity[$roomId] = $roomId === 1
                ? $maxSize + 10
                : max(20, (int) round($maxSize * (0.4 + mt_rand(0, 100) / 100)));
            $roomBuilding[$roomId] = 1 + (int) floor(($roomId - 1) / max(1, (int) ceil($roomCount / 3)));
        }

        return ProblemData::build(
            examIds: $examIds,
            examSize: $examSize,
            conflicts: ConflictGraph::build($examStudents),
            examStudents: $examStudents,
            slotIds: $slotIds,
            slotMeta: $slotMeta,
            roomCapacity: $roomCapacity,
            roomBuilding: $roomBuilding,
        );
    }
}
