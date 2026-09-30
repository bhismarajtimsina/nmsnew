#!/usr/bin/env bash

if [ -f /usr/local/bin/wca ]; then
    rm /usr/local/bin/wca
fi
ln -s /www/console /usr/local/bin/wca
chmod +x /usr/local/bin/wca
cd /www
source .env
source .version

chmod -R 777 /www/var/

wca cache:flush

echo "RUN @reboot WCA jobs"
wca schedule:exec-once &

echo "RUN SUPERVISORD"
#Run supervisord
supervisord -c /etc/supervisord.conf

echo "RUN CRONTAB"

sleep 5
