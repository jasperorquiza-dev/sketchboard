#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/sketchboard}"
APP_REPO="${APP_REPO:-https://github.com/jasperorquiza-dev/sketchboard.git}"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
DOMAIN_NAME="${DOMAIN_NAME:-sketchboard.online}"

DB_NAME="${DB_NAME:-sketchboard}"
DB_USER="${DB_USER:-sketchboard}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 16)}"

SMTP_HOST="${SMTP_HOST:-}"
SMTP_PORT="${SMTP_PORT:-587}"
SMTP_USER="${SMTP_USER:-}"
SMTP_PASS="${SMTP_PASS:-}"
SMTP_FROM_EMAIL="${SMTP_FROM_EMAIL:-${SMTP_USER}}"
SMTP_FROM_NAME="${SMTP_FROM_NAME:-Sketchboard}"
APP_SECRET_KEY="${APP_SECRET_KEY:-$(openssl rand -hex 32)}"

if [ -z "${SMTP_HOST}" ] || [ -z "${SMTP_USER}" ] || [ -z "${SMTP_PASS}" ]; then
    echo "Missing required SMTP environment variables."
    echo "Set: SMTP_HOST SMTP_USER SMTP_PASS"
    exit 1
fi

sudo apt update
sudo apt install -y apache2 mariadb-server certbot python3-certbot-apache git unzip
sudo apt install -y php php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl
sudo a2enmod rewrite headers ssl
sudo systemctl enable --now apache2 mariadb

sudo mkdir -p "${APP_DIR}"
sudo chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"

if [ ! -d "${APP_DIR}/.git" ]; then
    sudo -u "${APP_USER}" git clone "${APP_REPO}" "${APP_DIR}"
else
    cd "${APP_DIR}"
    sudo -u "${APP_USER}" git pull
fi

cd "${APP_DIR}"

cat > config.php <<EOF
<?php
declare(strict_types=1);

return [
    'app' => [
        'secret_key' => '${APP_SECRET_KEY}',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'user' => '${DB_USER}',
        'password' => '${DB_PASS}',
        'name' => '${DB_NAME}',
    ],
    'smtp' => [
        'host' => '${SMTP_HOST}',
        'port' => ${SMTP_PORT},
        'username' => '${SMTP_USER}',
        'password' => '${SMTP_PASS}',
        'from_email' => '${SMTP_FROM_EMAIL}',
        'from_name' => '${SMTP_FROM_NAME}',
    ],
];
EOF

sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

sudo mkdir -p rooms rooms_data
sudo chown -R www-data:"${APP_GROUP}" rooms rooms_data
sudo chmod -R 775 rooms rooms_data

sudo cp deploy/apache-sketchboard.conf /etc/apache2/sites-available/sketchboard.conf
if [ -n "${DOMAIN_NAME}" ]; then
    sudo sed -i "s/your-domain.com/${DOMAIN_NAME}/g" /etc/apache2/sites-available/sketchboard.conf
fi

sudo a2dissite 000-default.conf >/dev/null 2>&1 || true
sudo a2ensite sketchboard.conf
sudo apache2ctl configtest
sudo systemctl reload apache2

echo "Bootstrap complete."
echo "Run certbot next:"
if [ -n "${DOMAIN_NAME}" ]; then
    echo "sudo certbot --apache -d ${DOMAIN_NAME} -d www.${DOMAIN_NAME}"
fi
