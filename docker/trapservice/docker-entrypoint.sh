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
if [ "$TRAP_SERVICE_ENABLED" == "1" ] || [ "$TRAP_SERVICE_ENABLED" == "yes" ] || [ "$TRAP_SERVICE_ENABLED" == "true" ]; then
        echo "Trap service enabled, try starting..."
        /traplistener --config=/www/.trap-listener.yml
    else
        echo "Trap service disabled, sleeping 60sec ..."
        sleep 55
fi
sleep 5
done