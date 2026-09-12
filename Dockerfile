FROM composer:2 AS dependencies

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY src ./src
COPY website ./website
# The public image deliberately ships only the open core. Private add-ons are
# optional and must be installed by the deployment that is authorized to use
# them; the website detects their absence and keeps the core evaluator usable.

FROM php:8.4-cli

WORKDIR /app
COPY --from=dependencies /build /app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "website/public"]
