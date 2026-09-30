#!/usr/bin/env bash
rm -f /usr/bin/wca
ln -s /www/console /usr/bin/wca
chmod +x /www/console
chmod +x /www/rr
chmod -R 777 /www/var
cd /www
x=1
while [ $x -le 10 ]
do
  echo "Try starting wca WS server"
  wca -v system:ws-server
  x=$(( $x + 1 ))
  echo "Try starting wca WS server $x after 5 sec..."
  sleep 5
done
