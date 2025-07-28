#!/bin/bash -x

echo "Waiting.."
# custom sleep between 5 and 10 sec
sleep $((RANDOM % 6 + 5))

# Ensure directory exists
mkdir -p /var/run/dns-helper

# Vérifie que le fichier socket Unix existe
if [ ! -S /var/run/dns-helper/helper.sock ]; then
    echo "Socket file missing or not a socket"
    exit 1
fi

# Vérifie qu'on peut se connecter à 127.0.0.1:1080
if ! timeout 1 bash -c '</dev/tcp/127.0.0.1/1080'; then
    echo "TCP port 1080 not responding"
    exit 1
fi

# Tout va bien
echo "Healthcheck passed"
exit 0
