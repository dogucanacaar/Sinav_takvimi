<?php

namespace App\Import;

use App\Import\Reader\SheetReader;

/**
 * Elektronik tabloyu okur, doğrular ve ImportPlan üretir.
 *
 * Veritabanına hiç dokunmaz — bu yüzden doğrulamanın tamamı göç
 * çalıştırmadan test edilebilir.
 *
 * İki ilke:
 *   1. Bozuk satır aktarımı durdurmaz. Hatalı satır atlanır, sorun
 *      rapora yazılır, kalan satırlar işlenmeye devam eder.
 *   2. Hata mesajı kullanıcının düzeltebileceği kadar somut olur:
 *      hangi sayfa, hangi satır, hangi sütun, ne bekleniyordu.
 */
final class ImportParser
{
    /** Sayfa adı → kabul edilen yazımlar */
    private const SHEETS = [
        'Derslikler' => ['derslikler', 'derslik', 'rooms', 'siniflar'],
        'SaatDilimleri' => ['saatdilimleri', 'saatdilimi', 'oturumlar', 'slots', 'takvim'],
        'OgretimUyeleri' => ['ogretimuyeleri', 'ogretimuyesi', 'hocalar', 'lecturers'],
        'Musaitsizlik' => ['musaitsizlik', 'musaitolmadigisaatler', 'kisitlar', 'unavailability'],
        'Dersler' => ['dersler', 'ders', 'courses'],
        'Kayitlar' => ['kayitlar', 'kayit', 'ogrencidersleri', 'enrollments'],
    ];

    /** Sayfa → sütun anahtarı → kabul edilen başlıklar */
    private const COLUMNS = [
        'Derslikler' => [
            'bina' => ['bina', 'binaadi', 'blok', 'building'],
            'derslik' => ['derslik', 'derslikadi', 'sinif', 'ad', 'adi', 'name', 'room'],
            'kapasite' => ['kapasite', 'kontenjan', 'capacity'],
        ],
        'SaatDilimleri' => [
            'tarih' => ['tarih', 'gun', 'date', 'day'],
            'sira' => ['sira', 'siranumarasi', 'gunicisira', 'oturum', 'index'],
            'baslangic' => ['baslangic', 'baslangicsaati', 'saat', 'time', 'starttime'],
        ],
        'OgretimUyeleri' => [
            'adsoyad' => ['adsoyad', 'ad', 'adisoyadi', 'isim', 'ogretimuyesi', 'hoca', 'name'],
            'unvan' => ['unvan', 'title'],
            'bolum' => ['bolum', 'department'],
            'gecmisgorev' => ['gecmisgorev', 'gecmisgorevsayisi', 'gecmisyuk', 'pastduty', 'pastdutycount'],
        ],
        'Musaitsizlik' => [
            'adsoyad' => ['adsoyad', 'ad', 'isim', 'ogretimuyesi', 'hoca', 'name'],
            'tarih' => ['tarih', 'gun', 'date', 'day'],
            'sira' => ['sira', 'siranumarasi', 'gunicisira', 'oturum', 'index'],
        ],
        'Dersler' => [
            'derskodu' => ['derskodu', 'kod', 'kodu', 'code'],
            'dersadi' => ['dersadi', 'ad', 'adi', 'ders', 'name'],
            'bolum' => ['bolum', 'department'],
            'ogretimuyesi' => ['ogretimuyesi', 'hoca', 'sorumlu', 'lecturer'],
            'sure' => ['sure', 'sinavsuresi', 'suredk', 'dakika', 'duration'],
        ],
        'Kayitlar' => [
            'ogrencino' => ['ogrencino', 'ogrencinumarasi', 'numara', 'no', 'ogrenci', 'student'],
            'derskodu' => ['derskodu', 'kod', 'kodu', 'ders', 'code'],
        ],
    ];

    /** Bu sayfalar olmadan içe aktarma anlamsızdır. */
    private const REQUIRED_SHEETS = ['Derslikler', 'SaatDilimleri', 'Dersler', 'Kayitlar'];

    private const DEFAULT_DURATION = 60;

    public function parse(SheetReader $reader): ImportPlan
    {
        $plan = new ImportPlan;
        $found = $this->locateSheets($reader, $plan);

        foreach (self::REQUIRED_SHEETS as $sheet) {
            if (! isset($found[$sheet])) {
                $plan->addError(new RowError(
                    $sheet, null,
                    'Bu sayfa dosyada bulunamadı. Beklenen adlar: '.implode(', ', self::SHEETS[$sheet]).'.',
                ));
            }
        }

        // Sıra önemli: dersler öğretim üyelerine, kayıtlar derslere,
        // müsaitsizlik ise hem hocalara hem saat dilimlerine bakar.
        $this->parseRooms($reader, $found, $plan);
        $this->parseSlots($reader, $found, $plan);
        $this->parseLecturers($reader, $found, $plan);
        $this->parseCourses($reader, $found, $plan);
        $this->parseEnrollments($reader, $found, $plan);
        $this->parseUnavailability($reader, $found, $plan);

        $this->checkFeasibility($plan);

        return $plan;
    }

    /** @return array<string,string> kanonik ad => dosyadaki gerçek sayfa adı */
    private function locateSheets(SheetReader $reader, ImportPlan $plan): array
    {
        $bySlug = [];

        foreach ($reader->sheets() as $sheetName) {
            $bySlug[Value::slug($sheetName)] = $sheetName;
        }

        $found = [];

        foreach (self::SHEETS as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($bySlug[$alias])) {
                    $found[$canonical] = $bySlug[$alias];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Başlık satırını bulur ve sütun eşlemesini çıkarır.
     *
     * @return array{0:array<string,int>,1:array<int,string[]>}|null [anahtar => sütun no, kalan satırlar]
     */
    private function openSheet(SheetReader $reader, string $sheetName, string $canonical, ImportPlan $plan): ?array
    {
        $schema = self::COLUMNS[$canonical];
        $map = [];
        $data = [];

        // Tek geçişte hem başlık bulunur hem kalan satırlar toplanır.
        // Okuyucular üreteç (generator) döndürdüğü için akışı bir kez
        // gezip başa sarmak mümkün değildir.
        foreach ($reader->rows($sheetName) as $rowNumber => $values) {
            if ($map !== []) {
                $data[$rowNumber] = $values;

                continue;
            }

            $candidate = [];

            foreach ($values as $index => $value) {
                $slug = Value::slug((string) $value);

                foreach ($schema as $key => $aliases) {
                    if (in_array($slug, $aliases, true) && ! isset($candidate[$key])) {
                        $candidate[$key] = $index;
                    }
                }
            }

            // Başlık satırı: tanınan sütun içeren ilk satır.
            if ($candidate !== []) {
                $map = $candidate;
            }
        }

        if ($map === []) {
            $plan->addError(new RowError(
                $canonical, null,
                'Başlık satırı bulunamadı. İlk satırda sütun adları olmalı: '
                    .implode(', ', array_keys($schema)).'.',
            ));

            return null;
        }

        return [$map, $data];
    }

    private function value(array $row, array $map, string $key): string
    {
        $index = $map[$key] ?? null;

        return $index === null ? '' : Value::text((string) ($row[$index] ?? ''));
    }

    private function requireColumns(string $sheet, array $map, array $required, ImportPlan $plan): bool
    {
        $missing = array_values(array_diff($required, array_keys($map)));

        if ($missing === []) {
            return true;
        }

        $plan->addError(new RowError(
            $sheet, null,
            'Zorunlu sütun eksik: '.implode(', ', $missing).'.',
        ));

        return false;
    }

    private function parseRooms(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['Derslikler'])) {
            return;
        }

        $opened = $this->openSheet($reader, $found['Derslikler'], 'Derslikler', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('Derslikler', $map, ['derslik', 'kapasite'], $plan)) {
            return;
        }

        $seen = [];

        foreach ($rows as $rowNumber => $values) {
            $name = $this->value($values, $map, 'derslik');
            $building = $this->value($values, $map, 'bina');
            $capacityRaw = $this->value($values, $map, 'kapasite');

            if ($name === '' && $building === '' && $capacityRaw === '') {
                continue; // boş satır
            }

            if ($name === '') {
                $plan->addError(new RowError('Derslikler', $rowNumber, 'Derslik adı boş.', 'derslik'));

                continue;
            }

            if (isset($seen[$name])) {
                $plan->addError(new RowError(
                    'Derslikler', $rowNumber,
                    "\"{$name}\" dersliği daha önce {$seen[$name]}. satırda tanımlandı.", 'derslik',
                ));

                continue;
            }

            $capacity = Value::integer($capacityRaw);

            if ($capacity === null || $capacity <= 0) {
                $plan->addError(new RowError(
                    'Derslikler', $rowNumber,
                    "Kapasite pozitif bir tam sayı olmalı, gelen: \"{$capacityRaw}\".", 'kapasite',
                ));

                continue;
            }

            if ($building === '') {
                $building = 'Belirtilmemiş';
                $plan->addError(RowError::warning(
                    'Derslikler', $rowNumber,
                    "\"{$name}\" için bina belirtilmemiş; \"Belirtilmemiş\" binasına alındı. "
                        .'Bina bilgisi E3 (aynı gün farklı bina) kuralını etkiler.', 'bina',
                ));
            }

            $seen[$name] = $rowNumber;
            $plan->buildings[$building] = true;
            $plan->rooms[] = ['building' => $building, 'name' => $name, 'capacity' => $capacity];
        }
    }

    private function parseSlots(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['SaatDilimleri'])) {
            return;
        }

        $opened = $this->openSheet($reader, $found['SaatDilimleri'], 'SaatDilimleri', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('SaatDilimleri', $map, ['tarih', 'baslangic'], $plan)) {
            return;
        }

        $seen = [];
        $autoIndex = [];

        foreach ($rows as $rowNumber => $values) {
            $dayRaw = $this->value($values, $map, 'tarih');
            $startRaw = $this->value($values, $map, 'baslangic');
            $indexRaw = $this->value($values, $map, 'sira');

            if ($dayRaw === '' && $startRaw === '' && $indexRaw === '') {
                continue;
            }

            $day = Value::date($dayRaw);

            if ($day === null) {
                $plan->addError(new RowError(
                    'SaatDilimleri', $rowNumber,
                    "Tarih anlaşılamadı: \"{$dayRaw}\". Örnek: 12.06.2026", 'tarih',
                ));

                continue;
            }

            $startsAt = Value::time($startRaw);

            if ($startsAt === null) {
                $plan->addError(new RowError(
                    'SaatDilimleri', $rowNumber,
                    "Saat anlaşılamadı: \"{$startRaw}\". Örnek: 09:00", 'baslangic',
                ));

                continue;
            }

            // Sıra numarası verilmemişse aynı gün içinde sırayla üretilir.
            // Bu numara "art arda sınav" (E2) kuralının tek dayanağıdır.
            $index = Value::integer($indexRaw);

            if ($index === null || $index <= 0) {
                if ($indexRaw !== '') {
                    $plan->addError(new RowError(
                        'SaatDilimleri', $rowNumber,
                        "Sıra numarası pozitif tam sayı olmalı, gelen: \"{$indexRaw}\".", 'sira',
                    ));

                    continue;
                }

                $index = ($autoIndex[$day] ?? 0) + 1;
            }

            $autoIndex[$day] = max($autoIndex[$day] ?? 0, $index);
            $key = $day.'#'.$index;

            if (isset($seen[$key])) {
                $plan->addError(new RowError(
                    'SaatDilimleri', $rowNumber,
                    "{$day} gününün {$index}. oturumu daha önce {$seen[$key]}. satırda tanımlandı.", 'sira',
                ));

                continue;
            }

            $seen[$key] = $rowNumber;
            $plan->slots[] = ['day' => $day, 'index' => $index, 'starts_at' => $startsAt];
        }
    }

    private function parseLecturers(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['OgretimUyeleri'])) {
            return;
        }

        $opened = $this->openSheet($reader, $found['OgretimUyeleri'], 'OgretimUyeleri', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('OgretimUyeleri', $map, ['adsoyad'], $plan)) {
            return;
        }

        $seen = [];

        foreach ($rows as $rowNumber => $values) {
            $name = $this->value($values, $map, 'adsoyad');

            if ($name === '') {
                continue;
            }

            if (isset($seen[$name])) {
                // İsim, dersler ve müsaitsizlik sayfalarında anahtar olarak
                // kullanılıyor; aynı ismin iki kaydı hangisinin kastedildiğini
                // belirsiz bırakır.
                $plan->addError(new RowError(
                    'OgretimUyeleri', $rowNumber,
                    "\"{$name}\" daha önce {$seen[$name]}. satırda tanımlandı. "
                        .'Aynı isim iki kez geçemez; ayırt edici bir ek kullanın.', 'adsoyad',
                ));

                continue;
            }

            $pastDutyRaw = $this->value($values, $map, 'gecmisgorev');
            $pastDuty = Value::integer($pastDutyRaw);

            if ($pastDutyRaw !== '' && ($pastDuty === null || $pastDuty < 0)) {
                $plan->addError(RowError::warning(
                    'OgretimUyeleri', $rowNumber,
                    "Geçmiş görev sayısı anlaşılamadı (\"{$pastDutyRaw}\"), 0 kabul edildi.", 'gecmisgorev',
                ));
                $pastDuty = 0;
            }

            $seen[$name] = $rowNumber;

            $plan->lecturers[] = [
                'name' => $name,
                'title' => $this->value($values, $map, 'unvan') ?: null,
                'department' => $this->value($values, $map, 'bolum') ?: null,
                'past_duty_count' => $pastDuty ?? 0,
            ];
        }
    }

    private function parseCourses(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['Dersler'])) {
            return;
        }

        $opened = $this->openSheet($reader, $found['Dersler'], 'Dersler', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('Dersler', $map, ['derskodu'], $plan)) {
            return;
        }

        $lecturers = array_column($plan->lecturers, 'name');
        $lecturerSet = array_flip($lecturers);
        $seen = [];

        foreach ($rows as $rowNumber => $values) {
            $code = $this->value($values, $map, 'derskodu');
            $name = $this->value($values, $map, 'dersadi');

            if ($code === '' && $name === '') {
                continue;
            }

            if ($code === '') {
                $plan->addError(new RowError('Dersler', $rowNumber, 'Ders kodu boş.', 'derskodu'));

                continue;
            }

            if (isset($seen[$code])) {
                $plan->addError(new RowError(
                    'Dersler', $rowNumber,
                    "\"{$code}\" kodu daha önce {$seen[$code]}. satırda kullanıldı.", 'derskodu',
                ));

                continue;
            }

            if ($name === '') {
                $name = $code;
                $plan->addError(RowError::warning(
                    'Dersler', $rowNumber, "\"{$code}\" için ders adı boş; kod kullanıldı.", 'dersadi',
                ));
            }

            $lecturer = $this->value($values, $map, 'ogretimuyesi') ?: null;

            if ($lecturer !== null && ! isset($lecturerSet[$lecturer])) {
                $plan->addError(RowError::warning(
                    'Dersler', $rowNumber,
                    "\"{$lecturer}\" öğretim üyeleri arasında yok; ders sorumlusuz bırakıldı.", 'ogretimuyesi',
                ));
                $lecturer = null;
            }

            $durationRaw = $this->value($values, $map, 'sure');
            $duration = Value::integer($durationRaw);

            if ($durationRaw !== '' && ($duration === null || $duration <= 0)) {
                $plan->addError(RowError::warning(
                    'Dersler', $rowNumber,
                    "Sınav süresi anlaşılamadı (\"{$durationRaw}\"), ".self::DEFAULT_DURATION.' dakika kabul edildi.',
                    'sure',
                ));
                $duration = null;
            }

            $seen[$code] = $rowNumber;

            $plan->courses[] = [
                'code' => $code,
                'name' => $name,
                'department' => $this->value($values, $map, 'bolum') ?: null,
                'lecturer' => $lecturer,
                'duration_min' => $duration ?? self::DEFAULT_DURATION,
            ];
        }
    }

    private function parseEnrollments(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['Kayitlar'])) {
            return;
        }

        $opened = $this->openSheet($reader, $found['Kayitlar'], 'Kayitlar', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('Kayitlar', $map, ['ogrencino', 'derskodu'], $plan)) {
            return;
        }

        $courseSet = array_flip(array_column($plan->courses, 'code'));
        $unknownCourses = [];
        $seen = [];

        foreach ($rows as $rowNumber => $values) {
            $student = $this->value($values, $map, 'ogrencino');
            $course = $this->value($values, $map, 'derskodu');

            if ($student === '' && $course === '') {
                continue;
            }

            if ($student === '' || $course === '') {
                $plan->addError(new RowError(
                    'Kayitlar', $rowNumber,
                    'Öğrenci numarası ve ders kodu birlikte dolu olmalı.',
                ));

                continue;
            }

            if (! isset($courseSet[$course])) {
                // Aynı bilinmeyen kod yüzlerce satırda geçebilir; rapor
                // okunmaz hâle gelmesin diye kod başına bir kez bildirilir.
                if (! isset($unknownCourses[$course])) {
                    $unknownCourses[$course] = true;
                    $plan->addError(new RowError(
                        'Kayitlar', $rowNumber,
                        "\"{$course}\" Dersler sayfasında tanımlı değil; bu koda ait kayıtlar atlandı.", 'derskodu',
                    ));
                }

                continue;
            }

            $key = $student.'#'.$course;

            if (isset($seen[$key])) {
                $plan->addError(RowError::warning(
                    'Kayitlar', $rowNumber,
                    "{$student} öğrencisinin {$course} kaydı tekrar ediyor; bir kez sayıldı.",
                ));

                continue;
            }

            $seen[$key] = true;
            $plan->enrollments[] = ['student' => $student, 'course' => $course];
        }
    }

    private function parseUnavailability(SheetReader $reader, array $found, ImportPlan $plan): void
    {
        if (! isset($found['Musaitsizlik'])) {
            return; // İsteğe bağlı sayfa.
        }

        $opened = $this->openSheet($reader, $found['Musaitsizlik'], 'Musaitsizlik', $plan);

        if ($opened === null) {
            return;
        }

        [$map, $rows] = $opened;

        if (! $this->requireColumns('Musaitsizlik', $map, ['adsoyad', 'tarih'], $plan)) {
            return;
        }

        $lecturerSet = array_flip(array_column($plan->lecturers, 'name'));

        $slotSet = [];

        foreach ($plan->slots as $slot) {
            $slotSet[$slot['day'].'#'.$slot['index']] = true;
        }

        foreach ($rows as $rowNumber => $values) {
            $name = $this->value($values, $map, 'adsoyad');
            $dayRaw = $this->value($values, $map, 'tarih');
            $indexRaw = $this->value($values, $map, 'sira');

            if ($name === '' && $dayRaw === '') {
                continue;
            }

            if (! isset($lecturerSet[$name])) {
                $plan->addError(new RowError(
                    'Musaitsizlik', $rowNumber,
                    "\"{$name}\" öğretim üyeleri arasında yok.", 'adsoyad',
                ));

                continue;
            }

            $day = Value::date($dayRaw);

            if ($day === null) {
                $plan->addError(new RowError(
                    'Musaitsizlik', $rowNumber, "Tarih anlaşılamadı: \"{$dayRaw}\".", 'tarih',
                ));

                continue;
            }

            $index = Value::integer($indexRaw);

            if ($index === null) {
                $plan->addError(new RowError(
                    'Musaitsizlik', $rowNumber,
                    'Hangi oturum olduğu belirtilmeli (sıra numarası).', 'sira',
                ));

                continue;
            }

            if (! isset($slotSet[$day.'#'.$index])) {
                $plan->addError(new RowError(
                    'Musaitsizlik', $rowNumber,
                    "{$day} gününün {$index}. oturumu SaatDilimleri sayfasında yok.", 'sira',
                ));

                continue;
            }

            $plan->unavailability[] = ['lecturer' => $name, 'day' => $day, 'index' => $index];
        }
    }

    /**
     * Veri tutarlı olsa bile çizelge kurulamayabilir. Bu kontroller
     * kullanıcıya sorunu *çözüm üretmeden önce* söyler — yarım saat
     * bekleyip "yerleştirilemedi" mesajı almaktan iyidir.
     */
    private function checkFeasibility(ImportPlan $plan): void
    {
        if ($plan->rooms === [] || $plan->slots === []) {
            return;
        }

        $sizes = [];

        foreach ($plan->enrollments as $enrollment) {
            $sizes[$enrollment['course']] = ($sizes[$enrollment['course']] ?? 0) + 1;
        }

        $largestRoom = max(array_column($plan->rooms, 'capacity'));
        $capacity = count($plan->slots) * count($plan->rooms);

        foreach ($plan->courses as $course) {
            $size = $sizes[$course['code']] ?? 0;

            if ($size === 0) {
                $plan->addError(RowError::warning(
                    'Dersler', null,
                    "\"{$course['code']}\" dersine kayıtlı öğrenci yok; bu dersin sınavı oluşturulmayacak.",
                ));

                continue;
            }

            if ($size > $largestRoom) {
                $plan->addError(new RowError(
                    'Dersler', null,
                    "\"{$course['code']}\" dersinde {$size} öğrenci var; en büyük derslik {$largestRoom} kişilik. "
                        .'Bu sınav hiçbir dersliğe yerleştirilemez.',
                ));
            }
        }

        $examCount = count(array_filter($sizes));

        if ($examCount > $capacity) {
            $plan->addError(new RowError(
                'SaatDilimleri', null,
                "{$examCount} sınav var ama saat dilimi × derslik = {$capacity} yer. "
                    .'Daha fazla saat dilimi veya derslik gerekiyor.',
            ));
        }
    }
}
