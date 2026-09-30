FROM meklis/php-wca:7.4-240726-libssh2
MAINTAINER support.local <dev@support.local>

RUN apt-get update && apt-get install -y \
    software-properties-common
RUN apt-get update && apt-get install -y \
    curl \
    cron \
    python3.4 \
    python3-pip
RUN pip3 install supervisor

ADD php.ini /usr/local/etc/php/conf.d/40-custom.ini
COPY cron /etc/cron.d/cron
COPY schedule-executor.sh /schedule-executor.sh
COPY schedule-exec-once.sh /schedule-exec-once.sh
COPY docker-entrypoint.sh /docker-entrypoint.sh

RUN mkdir -p /var/log/supervisord && mkdir -p /processes
COPY supervisord.conf /etc/supervisord.conf
COPY ./processes/* /processes/
RUN chmod +x /processes/*.sh && \
    chmod +x /schedule-executor.sh && \
    chmod +x /docker-entrypoint.sh && \
    chmod +x /schedule-exec-once.sh && \
    crontab /etc/cron.d/cron

#Added logrotate
RUN apt update && apt -y install logrotate
COPY ./logrotate-support.conf /etc/logrotate.d/support.conf
RUN chmod 655 /etc/logrotate.d/support.conf

RUN rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
ENV PATH="/usr/local/bin:${PATH}"
ENTRYPOINT ["/docker-entrypoint.sh"]
