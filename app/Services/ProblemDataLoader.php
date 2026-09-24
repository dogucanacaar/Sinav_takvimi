<?php

namespace App\Services;

use App\Solver\ConflictGraph;
use App\Solver\ProblemData;
use Illuminate\Support\Facades\DB;

/**
 * Veritabanı ile motor arasındaki tek köprü.
 *
 * Motor (App\Solver) veritabanını hiç bilmez. Bu sınıf tüm veriyi bir
 * kez okur ve düz dizilere çevirir; motor bundan sonra sadece bellekte
 * çalışır. Yüz binlerce hamle deneyen bir arama için bu ayrım şarttır:
 * hamle başına tek bir sorgu bile işi dakikalardan saatlere çıkarır.
 */
final class ProblemDataLoader
{
    public function load(int $tenantId): ProblemData
    {
        $exams = DB::table('exams')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'course_id', 'student_count']);

        $examIds = [];
        $examSize = [];
        $examByCourse = [];

        foreach ($exams as $exam) {
            $examIds[] = (int) $exam->id;
            $examSize[(int) $exam->id] = (int) $exam->student_count;
            $examByCourse[(int) $exam->course_id] = (int) $exam->id;
        }

        return ProblemData::build(
            examIds: $examIds,
            examSize: $examSize,
            conflicts: ConflictGraph::fromPairs($examIds, $this->conflictPairs($tenantId, $examByCourse)),
            examStudents: $this->examStudents($tenantId, $examByCourse, $examIds),
            slotIds: $this->slotIds($tenantId),
            slotMeta: $this->slotMeta($tenantId),
            roomCapacity: $this->roomCapacity($tenantId),
            roomBuilding: $this->roomBuilding($tenantId),
        );
    }

    /**
     * Çakışan ders çiftleri.
     *
     * a.course_id < b.course_id koşulu iki işi birden yapar: dersin
     * kendisiyle eşleşmesini engeller ve aynı çifti iki kez getirmez.
     *
     * Aynı sonuç ConflictGraph::build() ile bellekte de üretilebilir;
     * burada sorguyla yapılmasının nedeni, kayıt sayısı büyüdüğünde
     * gruplamanın veritabanında çok daha ucuz olmasıdır.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function conflictPairs(int $tenantId, array $examByCourse): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.course_id AS c1, b.course_id AS c2, COUNT(*) AS ortak
            FROM   enrollments a
            JOIN   enrollments b ON a.student_id = b.student_id
                                AND a.course_id < b.course_id
            WHERE  a.tenant_id = ?
            GROUP BY a.course_id, b.course_id
        SQL, [$tenantId]);

        $pairs = [];

        foreach ($rows as $row) {
            $a = $examByCourse[(int) $row->c1] ?? null;
            $b = $examByCourse[(int) $row->c2] ?? null;

            // Sınavı olmayan ders (kimsenin almadığı ders) atlanır.
            if ($a === null || $b === null) {
                continue;
            }

            $pairs[] = [$a, $b];
        }

        return $pairs;
    }

    /** @return array<int,int[]> examId => öğrenci id'leri */
    private function examStudents(int $tenantId, array $examByCourse, array $examIds): array
    {
        $examStudents = array_fill_keys($examIds, []);

        // chunk: 2500 öğrenci × 6 ders = 15.000 satır bellekte sorun değil,
        // ama gerçek bir fakültede bu sayı 100.000'i aşabilir.
        DB::table('enrollments')
            ->where('tenant_id', $tenantId)
            ->orderBy('course_id')
            ->orderBy('student_id')
            ->chunk(5000, function ($rows) use (&$examStudents, $examByCourse): void {
                foreach ($rows as $row) {
                    $examId = $examByCourse[(int) $row->course_id] ?? null;

                    if ($examId !== null) {
                        $examStudents[$examId][] = (int) $row->student_id;
                    }
                }
            });

        return $examStudents;
    }

    /** @return int[] kronolojik sırada saat dilimi id'leri */
    private function slotIds(int $tenantId): array
    {
        return array_map(intval(...), DB::table('slots')
            ->where('tenant_id', $tenantId)
            ->orderBy('day')
            ->orderBy('index_in_day')
            ->pluck('id')
            ->all());
    }

    /** @return array<int,array{day:string,index:int}> */
    private function slotMeta(int $tenantId): array
    {
        $meta = [];

        foreach (DB::table('slots')->where('tenant_id', $tenantId)->get(['id', 'day', 'index_in_day']) as $row) {
            $meta[(int) $row->id] = [
                'day' => substr((string) $row->day, 0, 10),
                'index' => (int) $row->index_in_day,
            ];
        }

        return $meta;
    }

    /** @return array<int,int> */
    private function roomCapacity(int $tenantId): array
    {
        return array_map(intval(...), DB::table('rooms')
            ->where('tenant_id', $tenantId)
            ->pluck('capacity', 'id')
            ->all());
    }

    /** @return array<int,int> */
    private function roomBuilding(int $tenantId): array
    {
        return array_map(intval(...), DB::table('rooms')
            ->where('tenant_id', $tenantId)
            ->pluck('building_id', 'id')
            ->all());
    }
}
