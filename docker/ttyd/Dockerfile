FROM meklis/php-wca:7.4-240726-libssh2
MAINTAINER support.local <dev@support.local>

ADD docker-entrypoint.sh /docker-entrypoint.sh
ADD php.ini /usr/local/etc/php/conf.d/40-custom.ini

RUN chmod +x /docker-entrypoint.sh