<?php

namespace App\Solver;

/**
 * Motorun ihtiyaç duyduğu her şeyin bellekteki hâli.
 *
 * Tasarım kararı: burada Eloquent modeli yoktur. Motor veritabanını hiç
 * bilmez. Nedeni iki tanedir:
 *   1. Motor veritabanı olmadan test edilebilsin.
 *   2. Tavlama benzetimi yüz binlerce hamle dener; her hamlede sorgu
 *      atılırsa iş bitmez. Tüm veri bir kez okunur, sonra sadece bellekte
 *      çalışılır.
 */
final class ProblemData
{
    /**
     * @param  int[]  $examIds  sınav id listesi
     * @param  array<int,int>  $examSize  examId => öğrenci sayısı
     * @param  array<int,int[]>  $conflicts  examId => çakıştığı sınav id'leri (çift yönlü)
     * @param  array<int,int[]>  $examStudents  examId => o sınava giren öğrenci id'leri
     * @param  int[]  $slotIds  saat dilimi id listesi (kronolojik sırada)
     * @param  array<int,array{day:string,index:int}>  $slotMeta  slotId => gün ve gün içi sıra
     * @param  array<int,int>  $roomCapacity  roomId => kapasite
     * @param  array<int,int>  $roomBuilding  roomId => bina id
     * @param  array<int,int[]>  $studentExams  studentId => girdiği sınav id'leri (türetilir)
     */
    public function __construct(
        public readonly array $examIds,
        public readonly array $examSize,
        public readonly array $conflicts,
        public readonly array $examStudents,
        public readonly array $slotIds,
        public readonly array $slotMeta,
        public readonly array $roomCapacity,
        public readonly array $roomBuilding,
        public readonly array $studentExams = [],
    ) {}

    /**
     * studentExams'ı examStudents'tan türeterek tam bir ProblemData üretir.
     * Ceza hesabı öğrenci bazlı çalıştığı için bu ters indeks şarttır.
     */
    public static function build(
        array $examIds,
        array $examSize,
        array $conflicts,
        array $examStudents,
        array $slotIds,
        array $slotMeta,
        array $roomCapacity,
        array $roomBuilding,
    ): self {
        $studentExams = [];

        foreach ($examStudents as $examId => $studentIds) {
            foreach ($studentIds as $studentId) {
                $studentExams[$studentId][] = $examId;
            }
        }

        // Çakışma listesinde eksik yön kalmasın: A–B varsa B–A da olsun.
        foreach ($conflicts as $examId => $neighbours) {
            foreach ($neighbours as $other) {
                if (! isset($conflicts[$other]) || ! in_array($examId, $conflicts[$other], true)) {
                    $conflicts[$other][] = $examId;
                }
            }
        }

        foreach ($examIds as $examId) {
            $conflicts[$examId] ??= [];
        }

        return new self(
            examIds: array_values($examIds),
            examSize: $examSize,
            conflicts: $conflicts,
            examStudents: $examStudents,
            slotIds: array_values($slotIds),
            slotMeta: $slotMeta,
            roomCapacity: $roomCapacity,
            roomBuilding: $roomBuilding,
            studentExams: $studentExams,
        );
    }

    /** @return int[] kapasitesi küçükten büyüğe sıralı derslik id'leri */
    public function roomIdsByCapacity(): array
    {
        $rooms = $this->roomCapacity;
        asort($rooms);

        return array_keys($rooms);
    }

    public function conflictDegree(int $examId): int
    {
        return count($this->conflicts[$examId] ?? []);
    }

    public function examCount(): int
    {
        return count($this->examIds);
    }
}
