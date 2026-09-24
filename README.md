# Sınav Programı ve Gözetmen Atama

Bir fakültenin sınav döneminde dersleri saat dilimlerine ve dersliklere yerleştiren,
ardından her sınava gözetmen atayan uygulama.

`proje-mimarisi.md` dosyasındaki 13 adımın tamamı yazıldı: veri aktarımı, çözüm motoru,
gözetmen ataması, ekranlar, yazdırılabilir çıktılar ve yetkilendirme.

---

## Hızlı bakış — motoru hemen çalıştır

Kurulum yapmadan, veritabanı olmadan, sadece PHP ile:

```bash
php tools/demo-solve.php          # gerçek boyutta bir çözüm üretir
php tools/run-tests.php           # motorun ve içe aktarmanın 74 testi
php tools/check-consistency.php   # blade/rota/sütun tutarlılığı
```

`demo-solve.php`, `DemoSeeder` ile aynı boyutta bir fakülte kurar
(120 ders, 2500 öğrenci, 30 derslik, 5 gün × 4 saat dilimi), çözer ve gözetmen atar:

```
  120 sınav, 2508 öğrenci, 15068 kayıt, 20 saat dilimi, 30 derslik
  Çakışma derecesi: ortalama 11, en yüksek 19

  Başlangıç ceza puanı : 327867
  En iyi ceza puanı    :  40835
  İyileşme             : 287032  (%87.5)
  Süre                 : 8.55 sn

    E1  aynı gün birden fazla sınav :   86010 →   36970   (8601 → 3697 olay)
    E2  art arda saat dilimi        :  194150 →       0   (7766 →    0 olay)
    E3  aynı gün farklı bina        :   42675 →     540   (2845 →   36 olay)
    E4  kapasite israfı             :    5032 →    3325

  ✓ Bağımsız denetleyici: katı kural ihlali yok.

  GÖZETMEN ATAMA
  Toplam görev  : 443        Yük sapması : 0.88 → 0.52  (%41 iyileşme)
  Yük aralığı   : 13 – 15    Dengeleme   : 9 hamle
  ✓ K4 dahil hiçbir gözetmen kuralı bozulmadı.
```

E2 ve E3 pratikte sıfıra iner. E1'in sıfıra inmemesi motorun eksiği değil, problemin
kendisidir: 5 gün var ve öğrenci başına 5–7 sınav düşüyor; 6 sınavı olan bir öğrencinin
en az bir gün iki sınava girmesi kaçınılmaz.

---

## Kurulum

Docker kurulu olsun, gerisi tek komut:

```
baslat.bat           # Windows (cift tiklayarak da calisir)
```

```bash
./baslat.sh          # Linux / macOS
```

Windows'ta `.ps1` uzantili betikler varsayilan olarak engellidir; `baslat.bat`
bu engeli sadece kendi calistirmasi icin asar, sistemde kalici bir degisiklik
yapmaz. Dogrudan PowerShell'den calistirmak isterseniz:
`powershell -ExecutionPolicy Bypass -File .\baslat.ps1`

Betik sırayla şunları yapar: `.env` oluşturur, veritabanını kaldırır, imajı
derler, PHP ve JS bağımlılıklarını kurar, göçleri çalıştırıp demo veriyi
yükler, varlıkları derler ve uygulamayı başlatır. Bittiğinde arayüz
http://localhost:8000 adresinde olur.

Adımların sırası önemli: kuyruk işçisi en son başlar. Önce başlarsa `vendor`
klasörü henüz yokken açılıp kapanır ve konuyla ilgisiz görünen hatalar verir.

**Sonraki çalıştırmalar.** `baslat` ilk kurulum içindir. Kod değiştikten sonra
(ya da bu depoyu güncelledikten sonra) uygulanması gerekenleri `guncelle` yapar
— imajı tazeler, bağımlılıkları eşitler, yeni göçleri uygular, varlıkları
yeniden derler, konteynerleri yeniden başlatır ve sonunda `durum` raporunu
basar. Demo veriye dokunmaz.

```
guncelle.bat         # Windows
./guncelle.sh        # Linux / macOS
```

Elle yapmak isterseniz:

```bash
cp .env.example .env
docker compose up -d db
docker compose build app
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan migrate --seed --force
docker compose run --rm app npm install && docker compose run --rm app npm run build
docker compose up -d app worker
```

`docker-compose.yml` üç servis kaldırır: `app` (PHP 8.3 + Laravel + Node),
`worker` (kuyruk işçisi) ve `db` (PostgreSQL 16). Kuyruk ve önbellek de
veritabanında tutulur; böylece çalışması için tek bir bağımlılık yeter.

Redis dosyada tanımlı ama varsayılan olarak başlatılmaz. İstenirse açıkça
kaldırılır ve iki ayar `redis` yapılır (`docker-compose.yml` içindeki
`QUEUE_CONNECTION` / `CACHE_STORE`, her iki serviste de aynı olmalı):

```bash
docker compose --profile redis up -d redis
```

Ayakta duran ama kimsenin kullanmadığı bir servis, arıza ararken yanlış yere
baktırdığı için varsayılan dışı bırakıldı.

```bash
docker compose logs -f app worker   # kayıtlar
docker compose ps                   # hangi servis ayakta
docker compose down                 # durdur
docker compose down -v              # durdur ve veritabanını da sil
```

### İlk çalıştırmada takılırsanız

Önce şunu çalıştırın — aşağıdaki tablodaki durumların çoğunu tek başına
isimlendirir:

```bash
docker compose run --rm app php artisan durum
```

Veritabanı bağlantısı, uygulanmamış göç, yürürlükteki kuyruk/önbellek
sürücüsü, kuyrukta takılan iş, derlenmemiş (ya da Blade'den eski kalmış)
arayüz varlıkları, kullanıcı ve veri sayısı, son çözümün durumu — hepsi tek
ekranda çıkar, sorun varsa ne yapılacağıyla birlikte.

| Belirti | Sebep ve çözüm |
|---------|----------------|
| `running scripts is disabled on this system` | Windows betik politikası. `baslat.bat` kullanın veya `powershell -ExecutionPolicy Bypass -File .\baslat.ps1` |
| `error during connect` / `docker daemon` | Docker Desktop açık değil. Açıp yeşil ışığı bekleyin. |
| `port is already allocated` (8000) | Başka bir uygulama 8000'i tutuyor. `docker-compose.yml` içinde `"8000:8000"` yerine `"8080:8000"` yazın. |
| `port is already allocated` (5432) | Bilgisayarda kurulu bir PostgreSQL var. `"5432:5432"` satırını silin — uygulama veritabanına konteyner ağından ulaşır, dışarı açılması şart değil. |
| `worker` sürekli yeniden başlıyor | `composer install` henüz çalışmamış. Betiği baştan çalıştırın; `vendor` oluştuğunda kendiliğinden düzelir. |
| `Vite manifest not found` | Varlıklar derlenmemiş: `docker compose run --rm app npm run build` |
| Yeni bir düğme/uyarı kutusu renksiz, biçimsiz görünüyor | Tailwind yalnızca derleme anında kaynakta gördüğü sınıfları CSS'e yazar. Blade dosyalarına yeni sınıf eklendiyse: `docker compose run --rm app npm run build` (ya da `guncelle`). `php artisan durum` bu tazeliği ayrıca kontrol eder. |
| Kod değişti ama uygulamaya yansımadı | Konteynerler eski ortam değişkenleriyle ayakta. `guncelle` çalıştırın; ortam değişkeni değişiklikleri ancak konteyner yeniden oluşturulunca geçer. |
| İş sonsuza kadar `queued` kalıyor | Kuyruk işçisi çalışmıyor. `docker compose logs worker --tail 20` ile bakın; `docker compose up -d worker` ile kaldırın. Üretim ekranındaki "Kuyruğu beklemeden şimdi üret" düğmesi bu arada çıkış yolu sunar. |
| `could not translate host name "db"` | Veritabanı servisi ayakta değil: `docker compose up -d db` ve `docker compose ps` ile kontrol edin. |
| `Permission denied` (storage/) | `docker compose run --rm app chmod -R 777 storage bootstrap/cache` |
| Giriş yapılamıyor, "E-posta veya parola hatalı" | Kullanıcılar henüz yüklenmemiş. Giriş ekranı hiç kullanıcı yoksa bunu ayrıca söyler. Çözüm: `docker compose exec app php artisan migrate:fresh --seed --force` |
| Kullanıcı unutulmuş / parola değiştirmek | `docker compose exec app php artisan kullanici:ekle eposta@ornek.edu.tr --parola=yeni` |

`--seed` ile gelen demo kullanıcılar (parola hepsinde `sinav2026`):

| E-posta | Rol | Ne görür |
|---------|-----|----------|
| yonetici@ornek.edu.tr | Yönetici | Her şey: veri aktarma, program üretme, çıktılar |
| bolum@ornek.edu.tr | Bölüm Başkanı | Programı ve gözetmen çizelgesini görür, üretemez |
| hoca@ornek.edu.tr | Öğretim Üyesi | Sadece kendi gözetmenlik görevleri |

### Komutlar

```bash
php artisan migrate --seed                     # demo fakülte verisi + kullanıcılar
php artisan import:file veriler.xlsx           # elektronik tablodan veri aktar
php artisan import:file veriler.xlsx --dogrula # sadece doğrula, yazma
php artisan import:file sablon.xlsx --sablon   # doldurulmuş örnek şablon üret
php artisan solve:run                          # çözüm üret ve raporla
php artisan solve:run --seed=42                # tekrar üretilebilir çalıştırma
php artisan solve:run --queue                  # kuyruğa bırak, bekleme
php artisan test                               # tüm testler (PHPUnit)
php artisan durum                              # kurulum ve servis durumu — "neden çalışmıyor?"

php artisan kullanici:ekle admin@fakulte.edu.tr --ad="Ayşe Yılmaz"
php artisan kullanici:ekle hoca@fakulte.edu.tr --rol=lecturer --hoca=12
php artisan kullanici:ekle admin@fakulte.edu.tr --parola=yeniparola   # parola değiştirir
```

Kayıt olma ekranı bilinçli olarak yok — kullanıcıları fakülte yönetimi tanımlar.
`kullanici:ekle` bunun karşılığı: ilk yöneticiyi açar, var olanın parolasını
değiştirir. Parola verilmezse rastgele üretilip bir kez ekrana yazılır.

---

## Mimari

```
Arayüz (Livewire)
  ImportWizard · SolutionLauncher · ScheduleBoard · SolutionCompare · InvigilationTable
        │
Uygulama katmanı (Laravel)
  ImportService · SolveService · ProblemDataLoader · PrintController
        │ kuyruğa bırakır
        ▼
SolveJob (kuyruk: veritabanı)  ──────►  PostgreSQL
        │
        ▼
Çözüm motoru (saf PHP, app/Solver)
  ConflictGraph · InitialBuilder · Penalty · MoveGenerator ·
  SimulatedAnnealing · HardConstraintChecker · InvigilationAssigner
```

**Temel tasarım kararı:** `app/Solver` ve `app/Import` altındaki hiçbir sınıf Laravel'i
bilmez. Eloquent modeli değil, düz PHP dizileri kullanırlar. Üç sonucu var:

1. Motorun ve doğrulamanın tüm testleri veritabanı olmadan, saniyeler içinde koşar.
2. Yüz binlerce hamle denenirken tek bir sorgu bile atılmaz — tüm veri bir kez okunur.
3. Doğrulama ile yazma ayrıldığı için kullanıcıya "şunlar yazılacak, onaylıyor musun?"
   diye sormak mümkün olur.

Veritabanına dokunan her şey `app/Services` altındadır.

### Dosya haritası

| Yol | İş |
|-----|-----|
| `app/Solver/ProblemData.php` | Tüm problemin bellekteki hâli |
| `app/Solver/ConflictGraph.php` | Ortak öğrencili dersleri komşu yapar (K1'in temeli) |
| `app/Solver/Schedule.php` | Çizelge + O(1) doluluk indeksleri |
| `app/Solver/InitialBuilder.php` | Açgözlü başlangıç çözümü |
| `app/Solver/HardConstraints.php` | Hamle öncesi K1–K3 kontrolü |
| `app/Solver/Penalty.php` | E1–E4, `total()` ve artımlı `delta()` |
| `app/Solver/SimulatedAnnealing.php` | Tavlama benzetimi |
| `app/Solver/HardConstraintChecker.php` | Motordan **bağımsız** denetleyici |
| `app/Solver/Invigilation/` | Gözetmen atama, yük dengeleme, kendi denetleyicisi |
| `app/Import/XlsxReader.php` | Bağımsız .xlsx okuyucu (ZipArchive + SimpleXML) |
| `app/Import/XlsxWriter.php` | Şablon üretimi ve testler için asgari .xlsx yazıcı |
| `app/Import/ImportParser.php` | Sayfa/sütun eşleme, satır doğrulama, hata raporu |
| `app/Services/` | Veritabanı köprüleri: içe aktarma, çözüm, gözetmen verisi |
| `app/Livewire/` | Beş ekran |
| `app/Support/ProgressStore.php` | İlerleme önbelleği — hatayı yutar, çözümü durdurmaz |
| `app/Support/RedisAvailability.php` | Redis yoksa veritabanı sürücüsüne düşer |
| `app/Console/Commands/StatusCommand.php` | `php artisan durum` — arıza teşhisi |
| `resources/views/print/` | Kapı listesi, gözetmen çizelgesi, derslik planı |
| `config/scheduling.php` | Ağırlıklar ve motor parametreleri |
| `baslat.*` / `guncelle.*` | İlk kurulum / değişiklikleri uygulama betikleri |

---

## Kurallar

### Bozulamaz (katı)

| Kod | Kural | Nerede uygulanıyor |
|-----|-------|--------------------|
| K1 | Aynı öğrencinin iki sınavı aynı saat diliminde olamaz | `HardConstraints`, çakışma grafiği üzerinden |
| K2 | Bir dersliğe aynı saat diliminde tek sınav | `Schedule` veri yapısı + veritabanında `UNIQUE` |
| K3 | Sınavın öğrenci sayısı derslik kapasitesini aşamaz | `HardConstraints` + `MoveGenerator` (baştan elenir) |
| K4 | Öğretim üyesi müsait olmadığı saate gözetmen atanamaz | `InvigilationAssigner` + `InvigilationChecker` |
| K5 | Her sınav tam olarak bir saat dilimi ve bir derslik | `Schedule` veri yapısı |

### Bozulabilir (esnek) — ceza puanı üretir

| Kod | Kural | Varsayılan ağırlık |
|-----|-------|--------------------|
| E1 | Bir öğrenciye aynı gün birden fazla sınav | 10 |
| E2 | Bir öğrenciye art arda iki saat diliminde sınav | 25 |
| E3 | Öğrencinin aynı gün farklı binalarda sınava girmesi | 15 |
| E4 | Dersliğin boş kalan kapasitesi (israf) | 1 |

Ağırlıklar `config/scheduling.php` içinde, `.env` üzerinden kod değiştirmeden
ayarlanır (`SCHED_W_E1` … `SCHED_W_E4`) ve üretim ekranından da değiştirilebilir.

---

## Veri aktarma

Tek bir `.xlsx` dosyası (ya da her sayfası ayrı `.csv` olan bir klasör) altı sayfa içerir:

| Sayfa | Sütunlar |
|-------|----------|
| Derslikler | Bina, Derslik, Kapasite |
| SaatDilimleri | Tarih, Sıra, Başlangıç |
| OgretimUyeleri | Ad Soyad, Unvan, Bölüm, Geçmiş Görev |
| Musaitsizlik *(isteğe bağlı)* | Ad Soyad, Tarih, Sıra |
| Dersler | Ders Kodu, Ders Adı, Bölüm, Öğretim Üyesi, Süre |
| Kayitlar | Öğrenci No, Ders Kodu |

Sayfa ve sütun adları esnektir: büyük/küçük harf, Türkçe karakter ve alt çizgi farkı
sorun değil ("Öğretim Üyesi", "ogretim_uyesi", "HOCA" aynı sütundur). Tarih
`12.06.2026`, `2026-06-12` ya da Excel'in sakladığı seri numarası olabilir.

Aktarım iki aşamalıdır: dosya önce doğrulanır ve **hiçbir şey yazılmadan** rapor
gösterilir; yazma kullanıcının onayıyla başlar. Bozuk satır aktarımı durdurmaz —
hatalı satır atlanır, sorun rapora yazılır, kalan satırlar işlenir.

Şablonu `php artisan import:file sablon.xlsx --sablon` ile veya arayüzdeki
"Şablonu indir" düğmesiyle alabilirsiniz.

---

## Yetkilendirme

Üç rol var ve ayrımları ekranların tamamını belirler:

- **admin** — veri aktarır, çözüm üretir, her şeyi görür
- **department_head** — çözüm üretemez; programı ve gözetmen çizelgesini görür
- **lecturer** — sadece kendi gözetmenlik görevlerini görür

Kontrol üç katmanda yapılır: menüde `@can`, ekran girişinde `Policy`, sorguda ise
`tenant_id` global scope'u ve öğretim üyesi için `where('lecturer_id', …)`. Ekranı
gizlemek tek başına yeterli değildir — adres çubuğuna id yazan biri için sorgunun
kendisi daralmış olmalıdır.

---

## Testler

```bash
php tools/run-tests.php           # vendor gerekmez — tests/Unit
php tools/check-consistency.php   # vendor gerekmez — statik tutarlılık
php artisan test                  # composer install sonrası, tamamı (SQLite)
php artisan test --configuration=phpunit.pgsql.xml   # üretimdeki veritabanıyla
composer check                    # ilk iki betik birlikte
./vendor/bin/pint                 # kod biçimi
```

Test takımı hem bellek içi SQLite hem PostgreSQL üzerinde koşuldu: **122 test,
845 iddia**, ikisinde de tamamı geçiyor. İki veritabanı her konuda aynı
davranmıyor, bu yüzden ikisi de denendi — ayrıntısı aşağıda.

`tools/run-tests.php`, `composer install` yapılmamış bir ortamda `tests/Unit`
altındaki sınıfları çalıştırabilmek için asgari bir PHPUnit eşdeğeri sağlar. Test
dosyaları her iki durumda da aynıdır — `php artisan test` aynı sınıfları gerçek
PHPUnit ile, üstüne de `tests/Feature` altındaki veritabanlı testleri koşar.

`tools/check-consistency.php` çerçeve ayağa kalkmadan çalışır ve "çalıştırınca hemen
patlayan" hataları yakalar. On üç denetim yapar:

| # | Denetim |
|---|---------|
| 1 | Blade yönergeleri dengeli mi (`@if` açık kalmış, `@endforeach` fazla) |
| 2 | `view('x.y')` karşılığı dosya var mı |
| 3 | `route('ad')` tanımlı mı |
| 4 | `<x-bilesen>` bulunuyor mu |
| 5 | `DB::table('x')` ve `'tablo.sutun'` referansları göçlerde var mı |
| 6 | `$fillable` sütunları göçle uyuşuyor mu; göç sırası yabancı anahtarlara uygun mu |
| 7 | `use App\...` ile çağrılan sınıflar gerçekten var mı |
| 8 | `config('scheduling.…')` anahtarları tanımlı mı |
| 9 | `wire:model` / `wire:click` hedefleri bileşende var mı |
| 10 | Rotalardaki denetleyici metotları var mı |
| 11 | Görünümdeki her `$degisken` bileşen tarafından sağlanıyor mu |
| 12 | Livewire bileşeninde `return redirect()` tuzağı |
| 13 | `phpunit.xml` gibi XML dosyaları iyi biçimli mi |

Sonuncusu en çok işe yarayanı: "Undefined variable" hatası sayfa açılana kadar
görünmez. 9 ve 11, Livewire'ın çalışma zamanında bulacağı hataları statik olarak
bulur.

Denetimlerin kendisi de sınandı: her biri için koda bilerek bir bozukluk konup
yakalandığı doğrulandı. Aksi hâlde "temiz" çıktısının bir anlamı olmazdı.

| Test | Ne doğruluyor |
|------|---------------|
| `ConflictGraphTest` | Ortak öğrencili dersler komşu, olmayanlar değil; komşuluk çift yönlü ve tekrarsız |
| `InitialBuilderTest` | İlk çizelgede katı ihlal yok; yerleşemeyen sınav hata değil, rapor |
| `HardConstraintTest` | K1, K3, K5 için bilerek bozulmuş çizelgenin yakalanması; geçersiz hamlenin reddi |
| `PenaltyDeltaTest` | `delta()` sonucunun `total()` farkına **birebir** eşit olması |
| `AnnealingTest` | Ceza puanının düşmesi; sabit tohumla aynı sonucun üretilmesi |
| `InvigilationTest` | K4, aynı saatte çift görev, geçmiş yükün sayılması, sapmanın düşmesi |
| `ImportParserTest` | **Bozuk satırların aktarımı durdurmadan raporlanması**; esnek sütun adları |
| `XlsxRoundTripTest` | Yazılan .xlsx'in geri okunması; boş hücrelerin sütun hizasını bozmaması |
| `ValueTest` | Tarih/saat/sayı yazımlarının normalleşmesi |

Veritabanı gerektiren testler (`php artisan test` ile, SQLite üzerinde):

| Test | Ne doğruluyor |
|------|---------------|
| `ImportTest` | Planın tablolara yazılması; sınavların kayıtlardan türetilmesi; yeniden aktarımda eski verinin temizlenmesi; hatalı planın hiç yazılmaması |
| `SolveTest` | Uçtan uca çözüm: veri okuma → motor → yazma → gözetmen atama; sabit tohumla aynı sonucun üretilmesi; sınavı olmayan kurumda anlamlı hata |
| `AuthorizationTest` | Üç rolün ekran ayrımı; giriş/çıkış; başka kurumun çözümüne erişilememesi |
| `SmokeTest` | Demo fakülte yüklenip program üretildikten sonra her rolün her ekranı gerçek veriyle açması; üç yazdırılabilir çıktının üretilmesi |
| `LivewireInteractionTest` | Düğmelere basıldığında ne olduğu: dosya yükle → doğrula → onayla, "Üret", bölüm filtresi, arama, gün değiştirme, çözüm karşılaştırma |
| `UserCommandTest` | Kullanıcı oluşturma/parola değiştirme; kullanıcı yokken giriş ekranının uyarması; demo verinin iki kez yüklenmemesi |

Toplam: **122 test, 845 iddia** (`php artisan test`, ~13 sn SQLite / ~17 sn PostgreSQL).

Testlerin dışında, gerçek PostgreSQL üzerinde elle doğrulananlar:

| Ne | Sonuç |
|----|-------|
| `migrate --seed` | 17 göç + 120 ders / 2508 öğrenci / 15.057 kayıt, 0,6 sn |
| `rooms_capacity_positive` CHECK kısıtı | Veritabanında gerçekten oluşuyor (yalnızca pgsql dalında) |
| `solve:run` (varsayılan ayar) | 120/120 sınav yerleşti, ceza 318.757 → 42.661 (%86,6), 8 sn, 0 ihlal |
| `solve:run --queue` + `queue:work` | İş kuyruğa düştü, işçi aldı, çözüm tamamlandı; 120 çizelge satırı, 439 gözetmen görevi, 0 başarısız iş |

`SmokeTest` en pahalı ama en çok işe yarayan test: boş veriyle açılan bir sayfa
çoğu hatayı gizler; eksik sütun, yanlış birleştirme ve görünümdeki tanımsız
değişken ancak ızgara dolduğunda ortaya çıkar.

`PenaltyDeltaTest` en önemlisidir. Artımlı hesap hatalıysa motor yanlış yöne optimize
eder: program üretilir, sayı düşer, ama düşen sayı gerçeği göstermez.

---

## Geliştirme sırasında öğrenilenler

**Artımlı ceza hesabı (`Penalty::delta`).** Projedeki en kritik optimizasyon. Her
hamlede tüm çizelgeyi puanlamak yerine sadece etkilenen öğrencilerin katkısı yeniden
hesaplanır. Bir öğrencinin 5–7 sınavı olduğu için bu hesap çok küçüktür. `delta()` ile
`total()` aynı özel metodu kullanır; böylece ikisinin zamanla ayrışma riski kalkar.

**Demo verisinin çakışma yapısı.** İlk kurulumda öğrenciler derslerini sadece kendi
bölümlerinden rastgele seçiyordu. Sonuç: çakışma grafiği neredeyse tamamlandı
(ortalama derece 107/119) ve 120 sınavın yalnızca 46'sı yerleşti. Eksik olan sınıf
boyutuydu — 1. sınıf dersiyle 4. sınıf dersi ortak öğrenci taşımaz. Veri
(bölüm × sınıf) grupları üzerine kurulunca ortalama derece 8'e indi ve tüm sınavlar
yerleşti. Yapay olarak zorlaştırılmış bir problem, motorun iyi mi kötü mü çalıştığını
söylemez.

**Soğuma ne zaman işler.** Katı kuralla reddedilen hamleler sıcaklığı düşürmez. Aksi
hâlde sıcaklık, gerçekten değerlendirilen hamle sayısından çok daha hızlı iner ve arama
erken donar. Aynı sebeple `MoveGenerator` kapasitesi yetmeyen dersliği baştan elemez —
elerse arama bütçesinin yarısı boşa gitmez.

**`Schedule::assign` üstüne yazar.** Bir (saat, derslik) ikilisi her zaman en fazla bir
sınav tutar; oraya ikinci bir sınav atanırsa öncekisi yerinden kalkar. Böylece K2 veri
yapısı düzeyinde imkânsız hâle gelir. Bu davranış testle yakalandı: ilk hâlinde
indeksler sessizce tutarsızlaşıyordu.

**Livewire'da `$errors` ayrılmış bir addır.** İçe aktarma ekranında satır hatalarını
`$errors` içinde tutmak, Livewire'ın doğrulama hata torbasıyla çakışıyordu; ad
`$rowErrors` olarak değişti.

**Dosya yolu public bir Livewire özelliği olamaz.** Doğrulanan dosyanın yolunu public
bir özellikte saklamak, istemciye sunucudaki herhangi bir dosyayı okutabilirdi. Yol her
seferinde Livewire'ın imzalı geçici yüklemesinden türetiliyor.

**Livewire bileşeninde yönlendirme ve `abort()`.** Bu ikisi, uygulama ilk kez
gerçekten çalıştırıldığında çıktı. Bileşenin içinde `redirect()` helper'ı
Laravel'in `RedirectResponse`'unu değil Livewire'ın kendi akıcı Redirector'ünü
döndürür; onu `return` etmek "Redirector could not be converted to int" ile
500 verir. Daha sinsisi: `mount()` içinden `abort(403)` etmek, Livewire'ın istek
boyunca değiştirdiği `redirect` kap bağlamasını geri koymasına fırsat vermiyor
ve aynı süreçteki *sonraki* yönlendirmeler bozuluyor — yani hata, onu doğuran
sayfada değil bir sonraki sayfada patlıyor. Çözüm: sayfa seviyesindeki yetki ve
yönlendirme kararları rota katmanında (`can:` ara katmanı ve `RedirectLecturers`),
bileşenin içinde değil. Eylem metotları kendi kontrollerini sürdürüyor.

**`Collection::map` geri çağrıya anahtarı da verir.** `->pluck('id', 'name')->map(intval(...))`
zararsız görünüyor ama `intval($id, $name)` demek oluyor ve ikinci parametre taban
olduğu için TypeError fırlatıyor. Beş testi birden düşüren buydu.

**`solve:run` senkron kuyrukta çözümü iki kez üretiyordu.** Kayıt açmakla işi
kuyruğa bırakmak tek metottaydı; `QUEUE_CONNECTION=sync` olan bir ortamda
`dispatch` işi anında çalıştırıyor, ardından komut `execute()` deyince aynı çözüm
yeniden hesaplanıyordu. `create()` ve `start()` ayrıldı.

**phpredis eklentisi Dockerfile'da yoktu.** Kuyruk işçisi açılır açılmaz
`Class "Redis" not found` ile kapanıyordu. Bu da ancak gerçek çalıştırmada
görülebilecek türden: imaj derleniyor, uygulama açılıyor, sadece işçi ölüyor.
Arayüzde görünen tek şey ise sonsuza kadar "kuyrukta bekliyor" yazısıydı —
yani arızanın olduğu yer ile belirtisinin göründüğü yer arasında hiçbir bağ
yoktu. Üç ayrı önlem alındı: kuyruk ve önbellek varsayılan olarak
veritabanında tutuluyor (Redis isteğe bağlı), yapılandırma Redis derken Redis
yoksa uygulama kırılmak yerine veritabanına düşüp bunu kayıt dosyasına
yazıyor, ve `php artisan durum` komutu bütün bu zinciri tek ekranda
gösteriyor.

**İlerleme çubuğu, çözümü öldürebiliyordu.** `Cache::put` motorun ilerleme
geri çağrısının içinden çağrılıyordu; önbellek sürücüsü bozuksa istisna
yukarı çıkıp dakikalarca süren aramayı ortasından kesiyor ve çözümü
"başarısız" yapıyordu. Süs niteliğindeki bir özellik, asıl işi
durdurmamalı — `ProgressStore` artık hatayı yutuyor.

**Tailwind, derleme anında görmediği sınıfı CSS'e yazmaz.** Blade'e yeni bir
düğme eklendiğinde `npm run build` çalıştırılmazsa düğme sayfada *vardır* —
tıklanabilir, erişilebilirlik ağacında görünür — ama renksiz ve biçimsiz
olduğu için gözle fark edilmez. Belirtisi "düğme çalışmıyor"dur, sebebi ise
bambaşka yerdedir. `php artisan durum` artık derlenmiş CSS'in Blade
dosyalarından eski olup olmadığını da kontrol ediyor.

**`wire:loading.attr` hedefsiz yazılırsa bileşenin her isteğinde devreye
girer.** `wire:poll.2s` ile birlikte kullanıldığında düğme iki saniyede bir
kendiliğinden pasifleşiyor, o ana denk gelen tıklama sessizce kayboluyordu.
Yükleme durumunun hedefi (`wire:target`) her zaman açıkça yazılmalı.

**`LIKE` PostgreSQL'de büyük/küçük harfe duyarlıdır, SQLite'ta değildir.** Arama
kutusu SQLite üzerinde kusursuz çalışıyordu; üretimde "blm101" yazan kullanıcı
hiçbir sonuç alamayacaktı. Karşılaştırma artık iki tarafta da `lower()` ile
yapılıyor — `ILIKE` kullanmak sorguyu PostgreSQL'e bağlardı.

**PostgreSQL'de diziler (sequence) testler arasında sıfırlanmaz.** SQLite'ta ilk
kaydın id'si her testte 1 olduğu için `assertSet('watching', 1)` geçiyordu;
PostgreSQL'de 2 geldi. Testlerde id sabit yazmak taşınabilir değil.

**"E-posta veya parola hatalı" her zaman doğru mesaj değil.** Kurulum yapıldıktan
sonra giriş denendiğinde alınan bu mesaj, sorunun parolada olduğunu söylüyordu;
oysa sistemde hiç kullanıcı yoktu. Giriş ekranı artık bu iki durumu ayırıyor ve
ne yapılması gerektiğini yazıyor. Ayrıca `kullanici:ekle` komutu eklendi: kayıt
olma ekranı olmayan bir uygulamada ilk yöneticiyi açacak bir yol şart.

**`migrate --seed` ikinci kez çalıştırılırsa veri çoğalıyordu** — ikinci bir
kurum, ikinci bir 120 ders, ikinci bir 2500 öğrenci. `DemoSeeder` artık
veritabanında kurum varsa durup ne yapılması gerektiğini söylüyor.

**Bağımlılık eklememek de bir karar.** .xlsx okuma ve yazma için hazır kütüphane yerine
ZipArchive + SimpleXML kullanıldı; gereken tek şey hücrelerin metin karşılığıydı.
PDF için de kütüphane yok: üç çıktının üçü de düz tablo, tarayıcının "PDF olarak
kaydet" özelliği sayfa kırılmasını ve Türkçe yazı tipini zaten doğru yapıyor.

---

## Bilinen sınırlar

- Bir sınav tek bir dersliğe yerleşir; büyük sınavın birden çok dersliğe bölünmesi yok.
  İçe aktarmada bu durum önceden uyarı olarak bildirilir.
- Gözetmen sayısı öğrenci sayısına göre hesaplanır (varsayılan: 40 öğrenciye bir kişi);
  dersliğin fiziksel düzeni hesaba katılmaz.
- Kimlik doğrulama asgari düzeyde: kayıt olma, parola sıfırlama ve e-posta doğrulama
  bilinçli olarak yok — kullanıcıları fakülte yönetimi tanımlar.
