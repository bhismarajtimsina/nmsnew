#!/usr/bin/env bash
rm -f /usr/bin/wca
ln -s /www/console /usr/bin/wca
chmod +x /www/console
if [ -f /www/rr ]; then
  chmod +x /www/rr
fi
chmod -R 777 /www/var
cd /www
x=1
RR_PATH="${RR_PATH:-/usr/local/bin/rr}"
while [ $x -le 10 ]
do
  echo "Try starting wca..."
  $RR_PATH serve -c $RR_CONFIG
  x=$(( $x + 1 ))
  echo "Try starting wca $x after 5 sec..."
  sleep 5
done
