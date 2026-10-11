#!/bin/bash
set -e

# Ensure PHP is installed
if ! command -v php >/dev/null 2>&1; then
    echo "[start-server] PHP not found. Installing PHP and required extensions..."
    mkdir -p /etc/apt/apt.conf.d
    echo 'Dpkg::Options { "--force-confdef"; "--force-confold"; };' > /etc/apt/apt.conf.d/99force-conf
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends \
        php-cli \
        php-mbstring \
        php-xml \
        php-curl \
        php-sqlite3 \
        php-mysql
    echo "[start-server] PHP installation complete."
fi

echo "[start-server] Starting PHP built-in server on 0.0.0.0:3000..."
exec php -S 0.0.0.0:3000 router.php
