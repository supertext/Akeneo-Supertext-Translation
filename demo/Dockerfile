# Supertext Translation for Akeneo PIM: public demo (Railway). Build context = repository root:
#   docker build -f demo/Dockerfile -t supertext-akeneo-demo .
# One container runs Apache with Akeneo PIM Community Edition 2026.5 and the job queue worker.
# MySQL 8.4 and Elasticsearch 8 are separate services (see demo/.env.example).
#
# Stages are ordered so that a change to the bundle only repeats the last front-end build, not
# the Composer and Yarn installs (those take most of the time).

# --- PHP 8.3 with Akeneo's extensions ------------------------------------------------------
FROM mirror.gcr.io/library/php:8.3-apache AS php
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN install-php-extensions apcu bcmath exif gd imagick intl opcache pdo_mysql zip \
 && apt-get update && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/* \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers \
 && printf 'memory_limit=1024M\nmax_execution_time=600\nupload_max_filesize=32M\npost_max_size=32M\napc.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=60000\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/akeneo.ini
COPY --from=mirror.gcr.io/library/composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0

# --- Akeneo PIM (Composer) and its front-end packages (Yarn) --------------------------------
FROM php AS akeneo
COPY --from=mirror.gcr.io/library/node:18-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=mirror.gcr.io/library/node:18-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=mirror.gcr.io/library/node:18-bookworm /opt/ /opt/
RUN ln -s /opt/yarn-*/bin/yarn /usr/local/bin/yarn && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
WORKDIR /srv/pim
COPY demo/project/composer.json /srv/pim/composer.json
RUN php -d memory_limit=-1 /usr/bin/composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts \
 && vendor/akeneo/pim-community-dev/std-build/install-required-files.sh
RUN PUPPETEER_SKIP_DOWNLOAD=1 PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=1 CYPRESS_INSTALL_BINARY=0 yarn install --network-timeout 600000 \
 && PUPPETEER_SKIP_DOWNLOAD=1 NODE_OPTIONS=--max-old-space-size=4096 yarn packages:build

# --- The Supertext bundle, the demo's configuration and the front-end build ----------------
FROM akeneo AS build
COPY composer.json /srv/module/composer.json
COPY src /srv/module/src
COPY lib /srv/module/lib
COPY demo/project/ /srv/pim/
RUN composer config repositories.supertext '{"type": "path", "url": "/srv/module", "options": {"symlink": false, "versions": {"supertext/akeneo-supertext-translation": "dev-main"}}}' \
 && php -d memory_limit=-1 /usr/bin/composer require 'supertext/akeneo-supertext-translation:*@dev' --update-no-dev --no-interaction --no-progress --no-scripts \
 && composer dump-autoload --optimize --no-dev \
 && rm -rf var/cache && bin/console pim:installer:assets --clean \
 && yarn run less \
 && NODE_OPTIONS=--max-old-space-size=4096 yarn run webpack \
 && yarn run update-extensions \
 && rm -rf node_modules var/cache public/cache \
 && mkdir -p var/cache var/logs var/file_storage \
 && mkdir -p public/media && chown -R www-data:www-data var public/media

# --- Runtime -------------------------------------------------------------------------------
FROM php
COPY demo/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY --from=build /srv/pim /srv/pim
COPY demo/docker/entrypoint.sh /usr/local/bin/demo-entrypoint
COPY demo/docker/console.sh /usr/local/bin/demo-console
COPY demo/docker/env.sh /usr/local/lib/demo-env.sh
RUN chmod +x /usr/local/bin/demo-entrypoint /usr/local/bin/demo-console
WORKDIR /srv/pim
ENV PORT=8080
EXPOSE 8080
CMD ["demo-entrypoint"]
