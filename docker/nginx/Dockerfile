FROM nginx:1.21.0

COPY . /
RUN mkdir -p /tmp/cache
ENTRYPOINT ["bash", "/docker-entrypoint.sh"]

