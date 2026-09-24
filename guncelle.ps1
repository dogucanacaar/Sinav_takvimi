# Değişiklikleri uygula ve yeniden başlat (Windows PowerShell).
#
# `baslat.ps1` ilk kurulum içindir: imajı kurar, bağımlılıkları indirir,
# demo veriyi yükler. Kod değiştikten sonra bunların çoğu gereksiz, bir
# kısmı ise zararlıdır (demo veriyi tekrar yüklemeye çalışmak gibi).
#
# Bu betik yalnızca değişikliğin uygulanması için gerekenleri yapar:
#
#   .\guncelle.ps1
#
# "betik çalıştırma engellendi" hatası alırsanız:
#   powershell -ExecutionPolicy Bypass -File .\guncelle.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Adim($mesaj) {
    Write-Host ""
    Write-Host "==> $mesaj" -ForegroundColor Cyan
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "Docker bulunamadi. Once .\baslat.ps1 calistirin." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path .env)) {
    Write-Host "Kurulum yapilmamis. Once .\baslat.ps1 calistirin." -ForegroundColor Red
    exit 1
}

# Imaj tanimi (Dockerfile) degismis olabilir; degismediyse bu adim
# onbellekten donecegi icin birkac saniye surer.
Adim "Imaj guncelleniyor"
docker compose build app

Adim "PHP bagimliliklari esitleniyor"
docker compose run --rm app composer install

# Yeni goc varsa uygulanir; yoksa "nothing to migrate" der. --seed yok:
# mevcut veriyi bozmamak icin demo veri yalnizca ilk kurulumda yuklenir.
Adim "Yeni gocler uygulaniyor"
docker compose run --rm app php artisan migrate --force

# Blade dosyalarina yeni Tailwind sinifi eklendiyse CSS yeniden
# derlenmeden o sinif hic var olmaz: dugme gorunur ama renksiz cikar.
Adim "Arayuz varliklari derleniyor"
docker compose run --rm app npm install --no-audit --no-fund
docker compose run --rm app npm run build

Adim "Onbellekler temizleniyor"
docker compose run --rm app php artisan config:clear
docker compose run --rm app php artisan view:clear

# up -d, docker-compose.yml degistiginde konteynerleri yeniden olusturur;
# degismediyse dokunmaz. Ortam degiskeni degisikligi ancak boyle gecer.
Adim "Servisler yeniden baslatiliyor"
docker compose up -d --remove-orphans app worker

Adim "Durum kontrolu"
docker compose run --rm app php artisan durum

Write-Host ""
Write-Host "Guncellendi. Arayuz: http://localhost:8000" -ForegroundColor Green
