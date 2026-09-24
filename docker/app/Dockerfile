FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip intl opcache \
    && rm -rf /var/lib/apt/lists/*

# phpredis. Laravel'in varsayilan Redis istemcisi budur (REDIS_CLIENT=phpredis)
# ve kuyruk ile onbellek buna baglidir; eklenti olmadan kuyruk iscisi
# "Class \"Redis\" not found" diyerek aciliyormus gibi yapip kapanir.
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node, arayüzün CSS'ini derlemek için gerekli (Vite + Tailwind).
# Resmî node imajından kopyalanır; hem php:8.3-cli hem node:22-slim
# Debian bookworm tabanlı olduğu için ikili dosya doğrudan çalışır.
# NodeSource betiği yerine bunun tercih edilmesinin nedeni, imaj
# kurulumunun ek bir ağ kaynağına bağlı kalmaması.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Motor yoğun döngü çalıştırdığı için OPcache CLI'da da açık olmalı.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.jit_buffer_size=64M'; \
        echo 'opcache.jit=tracing'; \
        echo 'memory_limit=1G'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
