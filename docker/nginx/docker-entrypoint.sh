#!/bin/bash
envsubst '$VERSION $WEBPANEL_VERSION' < /agent.support > /etc/nginx/conf.d/default.conf
rm -Rf /tmp/www
mkdir -p /tmp/www
echo "Loading Support frontend from local bundle..."
cp -R /www/public/frontend/. /tmp/www/

# The rebranding sed pass that used to live here (relabeling the OLD vendor
# bundle's literal strings, e.g. "Agent:" -> "Backend:", "Panel:" ->
# "Frontend:") was removed — those are plain substring replacements with no
# word boundaries, and they corrupt the current frontend bundle wherever the
# same substring coincidentally appears in real code. Confirmed live:
# "Agent:" matched inside `navigator.userAgent:""` (turning it into
# `navigator.userBackend`, i.e. undefined, which crashed on the next
# `.split()` call and blanked the whole page), and "Panel:" matched
# `destroyInactivePanel:`/`faSolarPanel:`, corrupting an ant-design-vue prop
# name and a FontAwesome icon name. The current bundle doesn't contain any
# of the old bundle's literal branding text, so this step had no remaining
# purpose and was pure risk.

rm -rf /tmp/www/html

chmod -R 777 /tmp/www
cat /etc/nginx/conf.d/default.conf
nginx -g 'daemon off;'
