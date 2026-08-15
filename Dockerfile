FROM alpine:3.24

RUN apk add --no-cache apache2 su-exec php84 php84-apache2 php84-pdo_mysql php84-zip php84-zlib php84-fileinfo php84-session php84-ctype \
    && ln -s /usr/bin/php84 /usr/local/bin/php \
    && rm -rf /var/cache/apk/*

COPY docker/apache-accountplus.conf /etc/apache2/conf.d/accountplus.conf
COPY docker/entrypoint.sh /usr/local/bin/accountplus-entrypoint

RUN chmod +x /usr/local/bin/accountplus-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html
COPY .htaccess /etc/accountplus/htaccess
RUN php app/bin/check-runtime.php
RUN printf "display_errors=Off\nexpose_php=Off\nsession.upload_progress.enabled=0\nsession.save_path=/tmp\n" > /etc/php84/conf.d/99-security.ini
RUN ln -sf /var/www/html/app/storage/app/apache.htaccess /var/www/html/.htaccess

EXPOSE 80

ENTRYPOINT ["accountplus-entrypoint"]
CMD ["httpd", "-DFOREGROUND"]
