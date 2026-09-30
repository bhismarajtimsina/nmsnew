#!/usr/bin/env bash
cd /www
source .env

notifications=`wca  | grep notifications:telegram-bot | wc -l`

if [[ $notifications != 0 ]];
then
    echo "Notifications component installed and enabled, starting telegram bot listener"
    wca notifications:telegram-bot
    sleep 60
else
    echo "Notifications component not installed, sleep 300"
    sleep 300
fi


