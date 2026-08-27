FROM composer:2 AS dependencies

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY src ./src
COPY website ./website
COPY private/mathphp-visuals ./private/mathphp-visuals
COPY private/mathphp-explaining ./private/mathphp-explaining
# Build marker: visual-package extraction and image URI support (2026-08-27).
# The private packages point at released packages for normal users.
# During the monorepo image build, resolve both dependencies from copied source.
RUN composer config --working-dir=private/mathphp-explaining repositories.mathphp-path path /build \
    && composer config --working-dir=private/mathphp-explaining repositories.mathphp-visuals-path path /build/private/mathphp-visuals \
    && composer config --working-dir=private/mathphp-visuals repositories.mathphp-path path /build \
    && composer require --working-dir=private/mathphp-visuals mathphp/mathphp:@dev --no-update \
    && composer require --working-dir=private/mathphp-explaining mathphp/mathphp:@dev mathphp/mathphp-visuals:@dev --no-update \
    && composer install --working-dir=private/mathphp-explaining --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && test -f private/mathphp-visuals/src/VisualRepresentation.php \
    && rm -rf private/mathphp-explaining/vendor/mathphp/mathphp-visuals \
    && mkdir -p private/mathphp-explaining/vendor/mathphp/mathphp-visuals \
    && cp -a private/mathphp-visuals/src private/mathphp-explaining/vendor/mathphp/mathphp-visuals/src \
    && composer dump-autoload --working-dir=private/mathphp-explaining --optimize \
    && echo "mathphp-visuals-3306a55" \
    && rm -rf private/mathphp-explaining/vendor/mathphp/mathphp \
    && mkdir -p private/mathphp-explaining/vendor/mathphp/mathphp \
    && cp -a src private/mathphp-explaining/vendor/mathphp/mathphp/src

FROM php:8.4-cli

WORKDIR /app
COPY --from=dependencies /build /app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "website/public"]
