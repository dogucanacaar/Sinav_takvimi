#!/usr/bin/env bash
#
# Değişiklikleri uygula ve yeniden başlat (Linux / macOS).
#
# `baslat.sh` ilk kurulum içindir: imajı kurar, bağımlılıkları indirir,
# demo veriyi yükler. Kod değiştikten sonra bunların çoğu gereksiz, bir
# kısmı ise zararlıdır (demo veriyi tekrar yüklemeye çalışmak gibi).
#
# Bu betik yalnızca değişikliğin uygulanması için gerekenleri yapar:
#
#   ./guncelle.sh

set -euo pipefail

cd "$(dirname "$0")"

adim() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker bulunamadı. Önce ./baslat.sh çalıştırın."
    exit 1
fi

if [ ! -f .env ]; then
    echo "Kurulum yapılmamış. Önce ./baslat.sh çalıştırın."
    exit 1
fi

# İmaj tanımı (Dockerfile) değişmiş olabilir; değişmediyse bu adım
# önbellekten döneceği için birkaç saniye sürer.
adim "İmaj güncelleniyor"
docker compose build app

adim "PHP bağımlılıkları eşitleniyor"
docker compose run --rm app composer install

# Yeni göç varsa uygulanır; yoksa "nothing to migrate" der. --seed yok:
# mevcut veriyi bozmamak için demo veri yalnızca ilk kurulumda yüklenir.
adim "Yeni göçler uygulanıyor"
docker compose run --rm app php artisan migrate --force

# Blade dosyalarına yeni Tailwind sınıfı eklendiyse CSS yeniden
# derlenmeden o sınıf hiç var olmaz: düğme görünür ama renksiz çıkar.
adim "Arayüz varlıkları derleniyor"
docker compose run --rm app npm install --no-audit --no-fund
docker compose run --rm app npm run build

adim "Önbellekler temizleniyor"
docker compose run --rm app php artisan config:clear
docker compose run --rm app php artisan view:clear

# up -d, docker-compose.yml değiştiğinde konteynerleri yeniden oluşturur;
# değişmediyse dokunmaz. Ortam değişkeni değişikliği ancak böyle geçer.
adim "Servisler yeniden başlatılıyor"
docker compose up -d --remove-orphans app worker

adim "Durum kontrolü"
docker compose run --rm app php artisan durum

printf '\nGüncellendi. Arayüz: http://localhost:8000\n'
