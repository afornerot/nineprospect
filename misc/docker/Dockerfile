FROM reg.cadoles.com/envole/nineapache:8.5

USER root

# Extensions PHP supplémentaires requises par les dépendances Composer.
# php85-xmlreader est nécessaire pour phpoffice/phpspreadsheet (lecture xlsx).
RUN apk add --no-cache php85-xmlreader

RUN echo "* * * * * php /app/bin/console app:cron >> /app/var/log/cron.log 2>&1" > /crontab \
  && chmod g+w /crontab

USER apache
WORKDIR /app
COPY --chown=apache:apache . .

RUN composer install --no-interaction --no-scripts
RUN php bin/console assets:install public

# NOTE : la mise à jour du schéma BDD (d:s:u --force) n'est PAS lancée au build
# de l'image. Elle est exécutée au démarrage du container par
# misc/script/reconfigure.sh, qui est la procédure officielle du projet
# (cf. doc/installation.md et doc/prospection.md).

RUN mkdir -p /app/uploads \
    && mkdir -p /app/public/uploads/avatar \
    && mkdir -p /app/public/uploads/logo \
    && chmod g+w /app/uploads /app/public/uploads/avatar /app/public/uploads/logo \
    && cp -rf /app/public/medias/logo /app/public/uploads/logo \
    && cp -rf /app/public/medias/avatar /app/public/uploads/avatar

CMD ["/app/misc/script/reconfigure.sh","/etc/apache2/apache2.sh"]
