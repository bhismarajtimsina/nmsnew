#!/usr/bin/env bash
set -e

rm -f /usr/bin/wca
ln -s /www/console /usr/bin/wca
chmod +x /www/console
chmod -R 777 /www/var
cd /www

if command -v php-fpm >/dev/null 2>&1; then
  exec php-fpm -F
fi

if command -v php-fpm7.4 >/dev/null 2>&1; then
  exec php-fpm7.4 -F
fi

if command -v php-fpm74 >/dev/null 2>&1; then
  exec php-fpm74 -F
fi

echo "php-fpm binary not found in image"
exit 1
