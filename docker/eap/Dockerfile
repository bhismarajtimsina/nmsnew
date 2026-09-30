FROM ubuntu:18.04
LABEL maintainer="Max Boyar <max.boyar.a@gmail.com>"
WORKDIR /opt
COPY . /opt
RUN chmod +x /opt/support-eap
ENTRYPOINT ["/opt/support-eap", "-c", "/opt/proxy.conf.yml"]
