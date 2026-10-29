FROM php:8.2-apache-bookworm

ENV PORT=10000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'Listen 0.0.0.0:${PORT}\n' > /etc/apache2/ports.conf

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-cvs.ini
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/apache-start.sh /usr/local/bin/cvs-apache-start

# Copiar solo el codigo necesario, nunca configuraciones privadas ni documentos.
WORKDIR /var/www/html
COPY *.php ./
COPY includes/ ./includes/
COPY assets/ ./assets/
COPY scripts/*.php ./scripts/

# Normalizar CRLF incluso si se construye desde un checkout de Windows.
RUN sed -i 's/\r$//' /usr/local/bin/cvs-apache-start \
    && chmod 755 /usr/local/bin/cvs-apache-start \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && php -r 'foreach (["pdo_pgsql", "mbstring", "ctype", "session"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Falta extension PHP: $e\n"); exit(1); } }' \
    && apache2ctl -t

# EXPOSE es informativo; Listen y VirtualHost usan PORT al iniciar.
EXPOSE 10000
CMD ["/usr/local/bin/cvs-apache-start"]
