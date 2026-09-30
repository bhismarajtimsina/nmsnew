#!/usr/bin/env bash
cd /www
source .env

notifications=`wca  | grep notifications:service | wc -l`

if [[ $notifications != 0 ]];
then
    echo "Notifications component installed and enabled, starting notification service"
    wca notifications:service
else
    echo "Notifications component not installed, sleep 300"
    sleep 300
fi


