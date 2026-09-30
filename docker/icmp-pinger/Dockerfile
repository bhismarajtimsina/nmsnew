FROM ubuntu:18.04
LABEL maintainer="Max Boyar <max.boyar.a@gmail.com>"
RUN apt update && apt -y upgrade
ADD https://github.com/meklis/all-ok-pinger/releases/download/0.4/all-ok-pinger-linux-amd64 /opt/all-ok-pinger
COPY pinger.config.yml /opt/pinger.config.yml
RUN chmod +x /opt/all-ok-pinger
ENTRYPOINT ["/opt/all-ok-pinger", "-c", "/opt/pinger.config.yml"]
