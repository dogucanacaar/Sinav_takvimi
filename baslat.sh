#!/usr/bin/env bash
#
# Tek komutla kurulum ve başlatma (Linux / macOS).
#
# Adım sırası önemli: veritabanı önce ayağa kalkar, bağımlılıklar sonra
# kurulur, uygulama en son başlar. Tersi olursa kuyruk işçisi vendor
# klasörü yokken açılıp kapanır ve kafa karıştıran hatalar verir.
#
# Bu betik ilk kurulum içindir. Sonradan yapılan değişiklikleri uygulamak
# için: ./guncelle.sh
#
#   ./baslat.sh

set -euo pipefail

cd "$(dirname "$0")"

adim() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker bulunamadı. Docker Desktop kurup tekrar deneyin: https://docs.docker.com/get-docker/"
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose bulunamadı (Docker Desktop güncel mi?)."
    exit 1
fi

if [ ! -f .env ]; then
    adim ".env oluşturuluyor"
    cp .env.example .env
fi

adim "Veritabanı başlatılıyor"
docker compose up -d db

adim "Uygulama imajı hazırlanıyor (ilk seferde birkaç dakika sürer)"
docker compose build app

adim "PHP bağımlılıkları kuruluyor"
docker compose run --rm app composer install

adim "Uygulama anahtarı üretiliyor"
docker compose run --rm app php artisan key:generate --force

adim "Veritabanı kuruluyor ve demo veri yükleniyor"
docker compose run --rm app php artisan migrate --seed --force

adim "Arayüz varlıkları derleniyor"
docker compose run --rm app npm install --no-audit --no-fund
docker compose run --rm app npm run build

adim "Uygulama ve kuyruk işçisi başlatılıyor"
docker compose up -d app worker

cat <<'SON'

Hazır.

  Arayüz : http://localhost:8000

  Demo kullanıcılar (parola hepsinde: sinav2026)
    yonetici@ornek.edu.tr   Yönetici        — veri aktarır, program üretir
    bolum@ornek.edu.tr      Bölüm Başkanı   — programı görür, üretemez
    hoca@ornek.edu.tr       Öğretim Üyesi   — kendi gözetmenlik görevleri

  İlk program henüz üretilmedi. İki yol var:
    Arayüzden : "Üret" sekmesi
    Komutla   : docker compose exec app php artisan solve:run

  Kayıtlar  : docker compose logs -f app worker
  Durdurmak : docker compose down
SON
