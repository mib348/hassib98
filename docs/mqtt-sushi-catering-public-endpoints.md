# MQTT Public Endpoints for mqtt.sushi.catering

## Current Endpoint Policy

Use `mqtt.sushi.catering` for every public MQTT-facing endpoint. Keep Laravel and local monitoring on localhost.

| Surface | Public URL / Host | Local target | Notes |
|---|---|---|---|
| Raw MQTT over TLS | `mqtt.sushi.catering:443` | `127.0.0.1:9443` -> Mosquitto `8883` | External Raspberry Pi and tool clients use this. |
| Dashboard login | `https://mqtt.sushi.catering:8885/login` | `127.0.0.1:1885` -> Node-RED `1880` | Login-protected by Node-RED middleware. |
| Dashboard UI | `https://mqtt.sushi.catering:8885/ui` | `127.0.0.1:1885` -> Node-RED `1880` | Main monitoring dashboard. |
| Laravel internal MQTT | `127.0.0.1:1883` | Mosquitto `1883` | Never expose this publicly. |

## Required `.env` Values

Laravel should keep using the local broker:

```env
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_TLS_ENABLED=false
```

Docker should use the `mqtt.sushi.catering` certificate and localhost proxy targets:

```env
MQTT_TLS_CERT_DIR=/www/server/panel/vhost/cert/mqtt.sushi.catering
MQTT_TLS_LOCAL_PORT=9443
MQTT_DASHBOARD_LOCAL_PORT=1885
```

## aaPanel / Nginx Dashboard Proxy

Create an HTTPS site/listener for `mqtt.sushi.catering` on port `8885`, using the `mqtt.sushi.catering` certificate, and proxy it to Node-RED:

```nginx
server {
    listen 8885 ssl http2;
    server_name mqtt.sushi.catering;

    ssl_certificate /www/server/panel/vhost/cert/mqtt.sushi.catering/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/mqtt.sushi.catering/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:1885;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

## Raw MQTT on 443

If `app.sushi.catering` and `mqtt.sushi.catering` share the same public IP, raw MQTT on `443` requires SNI routing at the TCP layer. The MQTT SNI branch should proxy to `127.0.0.1:9443`.

Use this shape only after confirming your Nginx build has stream support:

```bash
nginx -V 2>&1 | grep -E -- '--with-stream|--with-stream_ssl_preread_module'
```

Stream routing shape:

```nginx
stream {
    map $ssl_preread_server_name $tls_backend {
        mqtt.sushi.catering 127.0.0.1:9443;
        default 127.0.0.1:8443;
    }

    server {
        listen 443;
        proxy_pass $tls_backend;
        ssl_preread on;
    }
}
```

The `default 127.0.0.1:8443` target means the existing HTTPS web vhosts must be moved behind that local HTTPS port before stream routing takes over public `443`.

## Verification

Dashboard:

```bash
curl -Ik https://mqtt.sushi.catering:8885/login
curl -Ik https://mqtt.sushi.catering:8885/ui
```

MQTT TLS:

```bash
openssl s_client -connect mqtt.sushi.catering:443 -servername mqtt.sushi.catering </dev/null
mosquitto_pub -h mqtt.sushi.catering -p 443 -u "$MQTT_USER" -P "$MQTT_PASSWORD" -t test/public -m hello --tls-use-os-certs
```

Laravel internal:

```bash
docker exec mosquitto mosquitto_pub -h 127.0.0.1 -p 1883 -t test/internal -m hello
```
