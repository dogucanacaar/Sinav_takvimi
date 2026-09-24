<?php

namespace App\Import;

/**
 * Doğrulanmış, normalleştirilmiş içe aktarma verisi.
 *
 * Bu nesne veritabanını bilmez. Dosyayı okuyup doğrulayan kod
 * (ImportParser) ile veritabanına yazan kod (ImportService) arasındaki
 * tek arayüzdür. Ayrım sayesinde doğrulamanın tamamı veritabanı
 * olmadan test edilebilir — ve kullanıcıya "şunları yazacağım, onaylıyor
 * musun?" diye sormak mümkün olur.
 */
final class ImportPlan
{
    /** @param RowError[] $errors */
    public function __construct(
        /** @var array<string,true> bina adları */
        public array $buildings = [],
        /** @var array<int,array{building:string,name:string,capacity:int}> */
        public array $rooms = [],
        /** @var array<int,array{day:string,index:int,starts_at:string}> */
        public array $slots = [],
        /** @var array<int,array{name:string,title:?string,department:?string,past_duty_count:int}> */
        public array $lecturers = [],
        /** @var array<int,array{lecturer:string,day:string,index:int}> */
        public array $unavailability = [],
        /** @var array<int,array{code:string,name:string,department:?string,lecturer:?string,duration_min:int}> */
        public array $courses = [],
        /** @var array<int,array{student:string,course:string}> */
        public array $enrollments = [],
        /** @var RowError[] */
        public array $errors = [],
    ) {}

    public function addError(RowError $error): void
    {
        $this->errors[] = $error;
    }

    /** @return RowError[] */
    public function errorsOnly(): array
    {
        return array_values(array_filter($this->errors, fn (RowError $e) => $e->isError()));
    }

    /** @return RowError[] */
    public function warningsOnly(): array
    {
        return array_values(array_filter($this->errors, fn (RowError $e) => ! $e->isError()));
    }

    public function hasErrors(): bool
    {
        return $this->errorsOnly() !== [];
    }

    /** Veri yazmaya değer bir şey var mı? */
    public function isEmpty(): bool
    {
        return $this->rooms === [] && $this->slots === [] && $this->courses === [];
    }

    /**
     * Benzersiz öğrenci numaraları.
     *
     * Dizi anahtarı olarak kullanıldığında PHP "2026001" gibi sayısal
     * metinleri tam sayıya çevirir; dışarı verirken metne geri çevrilir
     * ki numara her yerde aynı türde olsun.
     *
     * @return string[]
     */
    public function studentNumbers(): array
    {
        $numbers = [];

        foreach ($this->enrollments as $enrollment) {
            $numbers[$enrollment['student']] = true;
        }

        return array_map(strval(...), array_keys($numbers));
    }

    public function summary(): array
    {
        return [
            'binalar' => count($this->buildings),
            'derslikler' => count($this->rooms),
            'saat_dilimleri' => count($this->slots),
            'ogretim_uyeleri' => count($this->lecturers),
            'musaitsizlik' => count($this->unavailability),
            'dersler' => count($this->courses),
            'ogrenciler' => count($this->studentNumbers()),
            'kayitlar' => count($this->enrollments),
            'hata' => count($this->errorsOnly()),
            'uyari' => count($this->warningsOnly()),
        ];
    }
}
