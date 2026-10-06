#!/bin/bash
set -eo pipefail

# Se positionner sur la racine du projet
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd ${DIR}
cd ../..
DIR=$(pwd)

mkdir -p var/log

bin/console cache:clear

# Mise à jour du schéma BDD. C'est la procédure officielle du projet
# (cf. doc/installation.md:110-124 et doc/prospection.md:293,329-330).
# Le --dump-sql affiche les changements AVANT de les appliquer pour
# qu'on puisse suivre ce qui se passe. Le --force applique sans
# confirmation. Si rien ne change, c'est un no-op MySQL (rapide).
bin/console doctrine:schema:update --force --no-interaction --env=dev

bin/console app:init

supercronic -quiet -no-reap /crontab &
SUPERCRONIC_PID=$!

# Worker Messenger : consomme les transports async/failed en arrière-plan.
# Sans ce worker, les messages GeocodeProspectMessage et autres s'accumulent
# dans messenger_messages sans jamais être exécutés.
bin/console messenger:consume async failed --time-limit=25 -v > var/log/messenger-worker.log 2>&1 &
MESSENGER_PID=$!

"$@" &
APACHE_PID=$!

graceful_stop() {
    kill -TERM "$SUPERCRONIC_PID" "$MESSENGER_PID" "$APACHE_PID" 2>/dev/null || true
    wait
    exit 0
}
trap graceful_stop TERM INT

while true; do
    if ! kill -0 "$SUPERCRONIC_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP SUPERCRONIC" >> var/log/startup.log
        break
    fi
    if ! kill -0 "$MESSENGER_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP MESSENGER WORKER" >> var/log/startup.log
        break
    fi
    if ! kill -0 "$APACHE_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP APACHE" >> var/log/startup.log
        break
    fi
    sleep 2
done

kill -TERM "$SUPERCRONIC_PID" "$MESSENGER_PID" "$APACHE_PID" 2>/dev/null || true
wait
