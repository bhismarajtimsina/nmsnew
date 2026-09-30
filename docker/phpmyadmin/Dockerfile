FROM phpmyadmin:latest
RUN printf '%s\n' \
    'Alias /phpmyadmin /var/www/html' \
    '<Directory /var/www/html>' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-enabled/phpmyadmin-alias.conf && \
    echo "<?php  \$cfg['PmaAbsoluteUri'] = \$_SERVER['HTTP_X_ABSOLUTE_URL'];" > /etc/phpmyadmin/config.user.inc.php
