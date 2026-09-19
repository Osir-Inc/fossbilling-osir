# Toolchain image for unit tests / static analysis: the plugin's minimum PHP (8.3) + the
# extensions FOSSBilling itself requires (intl), + pcov for coverage. Build arg lets CI
# run the same suite on newer PHP versions.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev unzip git \
 && docker-php-ext-install intl pdo_mysql \
 && pecl install pcov && docker-php-ext-enable pcov \
 && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
