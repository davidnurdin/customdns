#!/bin/bash -x

rm -f /var/run/dns-helper/helper.sock
microsocks -p 1080 -i 127.0.0.1 &
exec socat  -d -d -v UNIX-LISTEN:/var/run/dns-helper/helper.sock,fork,reuseaddr,backlog=512 TCP:127.0.0.1:1080
