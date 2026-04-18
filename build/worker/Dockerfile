ARG PHP_FPM_VERSION=8.5-fpm-bookworm
FROM php:${PHP_FPM_VERSION}

ARG PROJECT_PATH=/var/www
ARG USERID=1000

RUN apt-get update && apt-get install -y \
    supervisor \
    git \
    unzip \
    zip \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql pcntl sockets zip mbstring gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /var/log/supervisor

RUN groupadd -g ${USERID} www \
    && useradd -u ${USERID} -ms /bin/bash -g www www

WORKDIR ${PROJECT_PATH}

USER www

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]