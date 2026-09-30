FROM meklis/php-wca:7.4-28
MAINTAINER support.local <dev@support.local>

ADD docker-entrypoint.sh /docker-entrypoint.sh
ADD php.ini /usr/local/etc/php/conf.d/40-custom.ini
ADD www.conf /usr/local/etc/php-fpm.d/zz-www.conf

RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN chmod +x /docker-entrypoint.sh

ENTRYPOINT ["bash", "/docker-entrypoint.sh"]
