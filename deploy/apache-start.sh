#!/bin/sh
set -eu

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*)
        echo 'PORT debe ser un numero entre 1 y 65535.' >&2
        exit 1
        ;;
esac
if [ "${#PORT}" -gt 5 ] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT debe ser un numero entre 1 y 65535.' >&2
    exit 1
fi
case "$PORT" in
    18012|18013|19099)
        echo 'PORT esta reservado por Render.' >&2
        exit 1
        ;;
esac
export PORT

apache2ctl -t
exec apache2-foreground
