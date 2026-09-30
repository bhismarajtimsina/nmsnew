FROM meklis/php-wca:7.4-240726-libssh2
MAINTAINER support.local <dev@support.local>

ARG RR_VERSION=2.12.3

ADD docker-entrypoint.sh /docker-entrypoint.sh
ADD php.ini /usr/local/etc/php/conf.d/40-custom.ini

RUN curl -fsSL "https://github.com/roadrunner-server/roadrunner/releases/download/v${RR_VERSION}/roadrunner-${RR_VERSION}-linux-amd64.tar.gz" \
    | tar -xz -C /tmp && \
    mv "/tmp/roadrunner-${RR_VERSION}-linux-amd64/rr" /usr/local/bin/rr && \
    chmod +x /usr/local/bin/rr && \
    rm -rf "/tmp/roadrunner-${RR_VERSION}-linux-amd64"

RUN rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN chmod +x /docker-entrypoint.sh
