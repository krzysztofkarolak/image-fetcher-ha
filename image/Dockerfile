FROM alpine:3.19.1

ARG ALPINE_PACKAGES="php83-openssl php83-gd php83-curl php83-fileinfo php83-mbstring php83-intl curl rsvg-convert fontconfig font-inter font-dejavu"
ARG COMPOSER_PACKAGES="google/cloud-storage stil/gd-text twig/twig:^3 symfony/yaml:^7"
ARG MDI_VERSION=7.4.47

ENV CONFIG_PATH=/srv/cfg
ENV XDG_CACHE_HOME=/tmp/.cache
ENV TZ=Europe/Warsaw
ENV PATH=$PATH:/srv/bin
ARG UID=65534
ARG GID=82

COPY ./composer.json /var/www/composer.json

COPY app/ /var/www/

RUN \
    ALPINE_PACKAGES="$(echo ${ALPINE_PACKAGES} | sed 's/,/ /g')" ;\
    ALPINE_COMPOSER_PACKAGES="" ;\
    if [ -n "${COMPOSER_PACKAGES}" ] ; then \
        ALPINE_COMPOSER_PACKAGES="php83-phar" ;\
        if [ -n "${ALPINE_PACKAGES##*php83-curl*}" ] ; then \
            ALPINE_COMPOSER_PACKAGES="php83-curl ${ALPINE_COMPOSER_PACKAGES}" ;\
        fi ;\
        if [ -n "${ALPINE_PACKAGES##*php83-mbstring*}" ] ; then \
            ALPINE_COMPOSER_PACKAGES="php83-mbstring ${ALPINE_COMPOSER_PACKAGES}" ;\
        fi ;\
    fi \

    && apk upgrade --no-cache \
    && apk add --no-cache nginx php83 php83-ctype php83-fpm php83-gd \
        s6 tzdata ${ALPINE_PACKAGES} ${ALPINE_COMPOSER_PACKAGES} \
    && mv /etc/php83 /etc/php \
    && ln -s /etc/php /etc/php83 \
    && ln -s $(which php83) /usr/local/bin/php \
    && rm -f /etc/nginx.conf /etc/nginx/http.d/default.conf /etc/php/php-fpm.d/www.conf \
    && rm -rf /etc/nginx/sites-* \
    && ln -s /dev/stderr /var/log/nginx/error.log \
    && cd /var/www \
    && if [ -n "${COMPOSER_PACKAGES}" ] ; then \
        wget -qO composer-installer.php https://getcomposer.org/installer \
        && php composer-installer.php --install-dir=/usr/local/bin --filename=composer ;\
    fi \
    && if [ -n "${COMPOSER_PACKAGES}" ] ; then \
        composer install \
        && composer require --no-update ${COMPOSER_PACKAGES} \
        && composer update --no-dev --optimize-autoloader ;\
    fi \
    && mkdir -p /srv/data \
    && mkdir -p /tmp/mdi /usr/share/mdi \
    && wget -qO- https://registry.npmjs.org/@mdi/svg/-/svg-${MDI_VERSION}.tgz | tar xz -C /tmp/mdi \
    && mv /tmp/mdi/package/svg /usr/share/mdi/svg \
    && rm -rf /tmp/mdi \
    && test -f /usr/share/mdi/svg/sofa.svg \
    && if [ -d /var/www/dashboards/fonts ] ; then \
        mkdir -p /usr/share/fonts/custom && cp /var/www/dashboards/fonts/* /usr/share/fonts/custom/ ;\
    fi \
    && fc-cache -f \
    && mkdir -p /etc/s6/services/nginx/supervise /etc/s6/services/php-fpm83/supervise \
    && mkfifo \
        /etc/s6/services/nginx/supervise/control \
        /etc/s6/services/php-fpm83/supervise/control \
    && chmod o+rwx /run /var/lib/nginx /var/lib/nginx/tmp \
    && chown -R ${UID}:${GID} /etc/s6 /run /srv/* /var/lib/nginx /var/www

WORKDIR /var/www
USER ${UID}:${GID}

COPY etc/ /etc/

VOLUME /run /srv/data /tmp /var/lib/nginx/tmp

EXPOSE 8080

ENTRYPOINT ["/etc/init.d/rc.local"]
