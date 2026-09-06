FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg fonts-dejavu-core libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 gd pdo_mysql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libimage-exiftool-perl imagemagick \
    && rm -rf /var/lib/apt/lists/* \
    && printf 'memory_limit=1024M\nupload_max_filesize=128M\npost_max_size=132M\nmax_input_time=300\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=1\nopcache.revalidate_freq=2\n' > /usr/local/etc/php/conf.d/studio.ini
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

CMD ["php", "artisan", "queue:work", "studio", "--queue=studio", "--sleep=2", "--tries=3", "--timeout=7200"]
