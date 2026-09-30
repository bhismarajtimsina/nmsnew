FROM mysql:8.0.40
ADD init.sql /docker-entrypoint-initdb.d
COPY custom.cnf /etc/mysql/conf.d/custom.cnf
RUN chmod 775 /etc/mysql/conf.d/custom.cnf
