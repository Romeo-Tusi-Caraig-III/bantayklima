FROM php:8.4-apache-bookworm AS app

WORKDIR /var/www/html
COPY .htaccess index.html config.php account.php file.php login.php logout.php privacy.php register.php unsubscribe.php ./
COPY admin/ admin/
COPY api/ api/
COPY assets/ assets/
COPY cli/ cli/
COPY lib/ lib/
COPY docker/bantayklima.conf /etc/apache2/conf-available/bantayklima.conf
RUN php -r 'foreach (["curl", "mbstring", "pdo_sqlite", "fileinfo"] as $ext) { if (!extension_loaded($ext)) exit(1); }' \
    && a2enmod headers rewrite \
    && a2enconf bantayklima \
    && install -d -o www-data -g www-data data uploads \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM app AS checks
COPY tests/ tests/
RUN find . -type f -name '*.php' -exec sh -ec 'for file do php -l "$file"; done' sh '{}' + \
    && for test in tests/*_test.php; do php "$test" || exit 1; done

FROM app AS production
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/risk.php") === false ? 1 : 0);'
