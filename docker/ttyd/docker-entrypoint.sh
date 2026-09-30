#!/usr/bin/env bash
rm -f /usr/bin/wca
ln -s /www/console /usr/bin/wca
chmod +x /www/console
chmod -R 777 /www/var


for (( ; ; ))
do
if [ -f /www/.env ]; then
  # Используйте export и source для загрузки переменных в окружение
  source /www/.env
fi
if [ "$CONSOLE_ENABLE_WEB" == "1" ] || [ "$CONSOLE_ENABLE_WEB" == "yes" ] || [ "$CONSOLE_ENABLE_WEB" == "true" ]; then
        echo "TTYd service enabled, try starting..."
        wca console:run-ttyd-server
    else
        echo "TTYd service disabled, sleeping 60sec ..."
        sleep 55
fi
sleep 5
done