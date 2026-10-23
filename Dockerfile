FROM php:8.3-apache

# Mindestversion mit den Fixes gegen PHP2Shell (CVE-2026-109618/-109619,
# Release 21.10.2026). Liefert der Build trotz "pull: true" in compose.yaml
# ein älteres Basis-Image, bricht er hier ab, statt still ein verwundbares
# PHP auszurollen. Bei neuen Sicherheits-Releases hier nachziehen.
ARG PHP_MIN_VERSION=8.3.36
RUN php -r 'if (version_compare(PHP_VERSION, getenv("PHP_MIN_VERSION"), "<")) { \
        fwrite(STDERR, "PHP " . PHP_VERSION . " ist älter als " . getenv("PHP_MIN_VERSION") \
            . ". Basis-Image neu holen: docker compose build --pull\n"); \
        exit(1); \
    }'

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# DocumentRoot auf public/ setzen (Front-Controller-Pattern)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY . /var/www/html/

# Eigene PHP-Einstellungen (Sitzungsdauer, Upload-Grenzen) — siehe config/php.ini
COPY config/php.ini /usr/local/etc/php/conf.d/berufsmesse.ini

# Sitzungen in einem eigenen Verzeichnis statt im flüchtigen /tmp.
RUN mkdir -p /var/lib/php/sessions \
    && chown www-data:www-data /var/lib/php/sessions \
    && chmod 700 /var/lib/php/sessions

RUN chown -R www-data:www-data /var/www/html/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
