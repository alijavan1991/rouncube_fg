# Test runner: the exact Roundcube release the plugin is verified against, plus Composer.
FROM roundcube/roundcubemail:1.7.x-apache@sha256:a6ef4cff166b5e2056238bb400ca84210bd796c83d859495ab67e60101ff2c55

COPY --from=composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c /usr/bin/composer /usr/local/bin/composer

ENV ROUNDCUBE_PATH=/usr/src/roundcubemail COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /usr/src/roundcubemail/plugins/zemzemeh_dlp
ENTRYPOINT []
CMD ["sh", "-c", "composer install --no-interaction --no-progress && vendor/bin/phpunit"]
