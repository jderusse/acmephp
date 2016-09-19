FROM alpine:edge

WORKDIR /srv

RUN echo "@testing http://nl.alpinelinux.org/alpine/edge/testing" >> /etc/apk/repositories

# PHP
RUN apk add --no-cache \
        php7 \
 && ln -s /usr/bin/php7 /usr/bin/php

# Performances matter
RUN apk add --no-cache \
        php7-opcache \
        php7-apcu@testing

# Symfony requirements
RUN apk add --no-cache \
        php7-intl \
        php7-ctype \
        php7-bcmath \
        php7-mbstring \
        php7-pcntl \
        php7-json \
        php7-xml \
        php7-dom \
        php7-posix \
        php7-session
RUN echo "date.timezone = UTC" > /etc/php7/conf.d/symfony.ini

RUN echo "opcache.enable_cli=1"    > /etc/php7/conf.d/opcache.ini \
 && echo "opcache.file_cache='/tmp/opcache'" >> /etc/php7/conf.d/opcache.ini \
 && echo "opcache.file_update_protection=0" >> /etc/php7/conf.d/opcache.ini \

 && mkdir /tmp/opcache

# Acme requirements
RUN apk add --no-cache \
        php7-openssl \
        openssl \
        ca-certificates

ENTRYPOINT ["/srv/bin/acme"]
CMD ["list"]

ADD . /srv

# Install vendors && warmup cache
RUN apk add --no-cache --virtual build-composer \
        php7-phar \
        php7-sockets \
        git \

 && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
 && php -r "if (hash_file('SHA384', 'composer-setup.php') === 'e115a8dc7871f15d853148a7fbac7da27d6c0030b848d9b3dc09e2a0388afed865e6a3d6b3c0fad45c48e2b5fc1196ae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" \
 && php composer-setup.php -- --install-dir=/usr/bin --filename=composer \
 && php -r "unlink('composer-setup.php');" \

 && composer global require "jderusse/composer-warmup" \

 && composer install --no-dev --no-scripts --no-suggest --optimize-autoloader \
 && composer warmup-opcode \

 && rm /usr/bin/composer \
 && rm -rf /root/.composer \

 && apk del build-composer --force
