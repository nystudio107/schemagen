ARG TAG=8.1-rc-cli-alpine3.15
FROM php:$TAG

# Install packages
RUN set -eux; \
    # Packages to install
    apk add --no-cache \
        curl \
    && \
    # Install Composer
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer \
    && \
    # Clean out directories that don't need to be part of the image
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /app/

CMD ["php", "scehmagen.php"]
