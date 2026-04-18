ARG PHP_FPM_VERSION=8.5-fpm-bookworm
FROM php:${PHP_FPM_VERSION}

ARG PROJECT_PATH=/var/www
ARG USERID=1000

ENV PROJECT_PATH=${PROJECT_PATH}

RUN apt-get update && apt-get install -y \
    build-essential \
    $PHPIZE_DEPS \
    git \
    curl \
    unzip \
    zip \
    locales \
    openssl \
    gnupg2 \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    libxml2-dev \
    libsnmp-dev \
    libmagickwand-dev \
    jpegoptim \
    optipng \
    pngquant \
    gifsicle \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install -j"$(nproc)" \
    pdo_mysql \
    mbstring \
    zip \
    exif \
    pcntl \
    snmp \
    xml \
    gd

RUN pecl install imagick && docker-php-ext-enable imagick

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN groupadd -g ${USERID} www \
    && useradd -u ${USERID} -ms /bin/bash -g www www

WORKDIR ${PROJECT_PATH}

USER www

EXPOSE 9000

CMD ["php-fpm"]