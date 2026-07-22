# Single-tenant image (docs/specs/10 §7).
#
# The one thing that makes this image unusual: it ships CHROMIUM. Invoices
# and books are rendered to PDF through headless Chrome (spatie/laravel-pdf
# → Browsershot → Puppeteer), and those PDFs are legal documents. An image
# without a browser boots fine and then 500s the first time a customer asks
# for their invoice.

FROM php:8.3-cli-bookworm

# --- system packages -------------------------------------------------------
# chromium + fonts: the PDF renderer. DejaVu is what the templates specify,
# and without a font the peso sign renders as a box.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        libicu-dev libzip-dev libpng-dev libonig-dev libxml2-dev \
        default-mysql-client \
        chromium fonts-dejavu-core fonts-liberation \
        nodejs npm \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath intl zip gd opcache

# bcmath matters more than it looks: the stock ledger's moving average and
# every quantity comparison run through it (spec 08 §2).

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- dependencies first, so a code change does not re-resolve them ---------
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
# Puppeteer uses the system Chromium rather than downloading its own: one
# browser, patched by apt with the rest of the OS.
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
RUN npm ci

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules/.cache

# Chromium refuses to run as root without --no-sandbox; running as a normal
# user is the safer half of that trade.
RUN useradd -m -u 1000 soro \
    && chown -R soro:soro /var/www/html/storage /var/www/html/bootstrap/cache
USER soro

EXPOSE 8080

# The health endpoint Laravel ships at /up backs the compose healthcheck.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
