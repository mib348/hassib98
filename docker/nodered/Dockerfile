FROM nodered/node-red:4.1.5-22

USER root

RUN if command -v apt-get >/dev/null 2>&1; then \
      apt-get update && \
      apt-get install -y --no-install-recommends curl openssl && \
      rm -rf /var/lib/apt/lists/*; \
    elif command -v apk >/dev/null 2>&1; then \
      apk add --no-cache curl openssl; \
    else \
      echo "Unsupported base image: could not find apt-get or apk" >&2; \
      exit 1; \
    fi

RUN npm install --unsafe-perm --no-update-notifier --no-fund node-red-dashboard@3.6.5
