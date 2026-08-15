FROM mariadb:10.11
RUN test -x /usr/bin/setpriv && rm /usr/local/bin/gosu
COPY --chmod=0755 gosu /usr/local/bin/mysql-switch-user
RUN ln -s /usr/local/bin/mysql-switch-user /usr/local/bin/gosu
COPY --chmod=0644 10-runtime-privileges.sh /docker-entrypoint-initdb.d/10-runtime-privileges.sh
COPY --chmod=0755 healthcheck.sh /usr/local/bin/accountplus-db-healthcheck
COPY entrypoint.sh /usr/local/bin/accountplus-db-entrypoint
RUN chmod 0755 /usr/local/bin/accountplus-db-entrypoint
ENTRYPOINT ["accountplus-db-entrypoint"]
CMD ["mariadbd", "--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci"]
