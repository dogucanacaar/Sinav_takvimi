# Tek komutla kurulum ve başlatma (Windows PowerShell).
#
# Adım sırası önemli: veritabanı önce ayağa kalkar, bağımlılıklar sonra
# kurulur, uygulama en son başlar. Tersi olursa kuyruk işçisi vendor
# klasörü yokken açılıp kapanır ve kafa karıştıran hatalar verir.
#
# Bu betik ilk kurulum içindir. Sonradan yapılan değişiklikleri uygulamak
# için: .\guncelle.ps1
#
#   .\baslat.ps1
#
# "betik çalıştırma engellendi" hatası alırsanız:
#   powershell -ExecutionPolicy Bypass -File .\baslat.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Adim($mesaj) {
    Write-Host ""
    Write-Host "==> $mesaj" -ForegroundColor Cyan
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "Docker bulunamadi. Docker Desktop kurup tekrar deneyin: https://docs.docker.com/get-docker/" -ForegroundColor Red
    exit 1
}

docker compose version *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Docker Compose bulunamadi (Docker Desktop guncel mi?)." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path .env)) {
    Adim ".env olusturuluyor"
    Copy-Item .env.example .env
}

Adim "Veritabani baslatiliyor"
docker compose up -d db

Adim "Uygulama imaji hazirlaniyor (ilk seferde birkac dakika surer)"
docker compose build app

Adim "PHP bagimliliklari kuruluyor"
docker compose run --rm app composer install

Adim "Uygulama anahtari uretiliyor"
docker compose run --rm app php artisan key:generate --force

Adim "Veritabani kuruluyor ve demo veri yukleniyor"
docker compose run --rm app php artisan migrate --seed --force

Adim "Arayuz varliklari derleniyor"
docker compose run --rm app npm install --no-audit --no-fund
docker compose run --rm app npm run build

Adim "Uygulama ve kuyruk iscisi baslatiliyor"
docker compose up -d app worker

Write-Host ""
Write-Host "Hazir." -ForegroundColor Green
Write-Host ""
Write-Host "  Arayuz : http://localhost:8000"
Write-Host ""
Write-Host "  Demo kullanicilar (parola hepsinde: sinav2026)"
Write-Host "    yonetici@ornek.edu.tr   Yonetici        - veri aktarir, program uretir"
Write-Host "    bolum@ornek.edu.tr      Bolum Baskani   - programi gorur, uretemez"
Write-Host "    hoca@ornek.edu.tr       Ogretim Uyesi   - kendi gozetmenlik gorevleri"
Write-Host ""
Write-Host "  Ilk program henuz uretilmedi. Iki yol var:"
Write-Host "    Arayuzden : 'Uret' sekmesi"
Write-Host "    Komutla   : docker compose exec app php artisan solve:run"
Write-Host ""
Write-Host "  Kayitlar  : docker compose logs -f app worker"
Write-Host "  Durdurmak : docker compose down"
