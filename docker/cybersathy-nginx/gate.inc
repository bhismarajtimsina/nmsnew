# Shared settings for the internal auth-gate locations in default.conf.
proxy_pass_request_body off;
proxy_set_header Content-Length "";
proxy_set_header X-Original-URI $request_uri;
