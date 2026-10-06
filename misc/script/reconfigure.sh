#!/bin/bash
set -eo pipefail

# Se positionner sur la racine du projet
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd ${DIR}
cd ../..
DIR=$(pwd)

mkdir -p var/log

# On ne lance PAS `d:s:u --force` à chaque démarrage : c'est une opération
# destructive (ALTER TABLE sur chaque colonne), longue et inutile si le schéma
# n'a pas changé. Le schema est mis à jour :
#  - au build de l'image (`compose build` ou premier `up`) via le Dockerfile
#  - manuellement quand on ajoute une entité : `d:s:u --force`
# Au boot, on se contente de vider le cache et de recharger les fixtures.
bin/console cache:clear
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
