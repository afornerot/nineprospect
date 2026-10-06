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

# Mise à jour du schéma BDD au build (équivalent de `d:s:u --force`).
# Cette commande n'est PAS rejouée à chaque démarrage du container, ce qui
# évite des opérations ALTER TABLE inutiles (et le risque de boucles).
# Pour appliquer manuellement un changement de schéma après le build :
#   docker compose exec -T nineprospect php bin/console doctrine:schema:update --force
RUN php bin/console doctrine:schema:update --force --no-interaction || true

RUN mkdir -p /app/uploads \
    && mkdir -p /app/public/uploads/avatar \
    && mkdir -p /app/public/uploads/logo \
    && chmod g+w /app/uploads /app/public/uploads/avatar /app/public/uploads/logo \
    && cp -rf /app/public/medias/logo /app/public/uploads/logo \
    && cp -rf /app/public/medias/avatar /app/public/uploads/avatar

CMD ["/app/misc/script/reconfigure.sh","/etc/apache2/apache2.sh"]
