# Sketchboard 2 GCP VM Setup

This app fits a single Google Compute Engine VM with Apache, PHP, and MariaDB. The setup below assumes:

- Ubuntu 24.04 LTS
- One public VM
- Apache serving the repo directly
- MariaDB running on the same VM
- HTTPS via Let's Encrypt

## 1. Create the VM

Use a small general-purpose instance to start, for example:

- Machine type: `e2-small` or `e2-medium`
- Boot disk: Ubuntu 24.04 LTS
- Firewall tags: allow `80` and `443`

Reserve a static external IP and point your domain DNS `A` record to that IP before requesting SSL.

## 2. Connect and install packages

SSH into the VM, then run:

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server certbot python3-certbot-apache git unzip
sudo apt install -y php php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl
sudo a2enmod rewrite headers ssl
sudo systemctl enable --now apache2 mariadb
```

If you want the automatic path, skip the manual steps below and use `deploy/gcp-vm-bootstrap.sh` with environment variables.

## 3. Create the application user and directories

```bash
sudo adduser --system --group --home /var/www/sketchboard sketchboard
sudo mkdir -p /var/www/sketchboard
sudo chown -R sketchboard:sketchboard /var/www/sketchboard
```

## 4. Deploy the code

```bash
sudo -u sketchboard git clone https://github.com/jasperorquiza-dev/sketchboard.git /var/www/sketchboard
cd /var/www/sketchboard
cp config.sample.php config.php
```

If the repo already exists on the VM:

```bash
cd /var/www/sketchboard
sudo -u sketchboard git pull
```

## 5. Create the database

Then create the app database and user:

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS sketchboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sketchboard'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON sketchboard.* TO 'sketchboard'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 6. Configure the app

Edit `config.php` and set:

- `app.secret_key`
- `database.*`
- `smtp.*`

Example:

```php
<?php
declare(strict_types=1);

return [
    'app' => [
        'secret_key' => 'generate-a-long-random-secret',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'user' => 'sketchboard',
        'password' => 'replace-with-a-strong-password',
        'name' => 'sketchboard',
    ],
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'noreply@example.com',
        'password' => 'app-password',
        'from_email' => 'noreply@example.com',
        'from_name' => 'Sketchboard',
    ],
];
```

Generate a strong app secret with:

```bash
openssl rand -hex 32
```

You can also let the bootstrap script generate none of this manually by exporting `APP_SECRET_KEY`, `DB_PASS`, `SMTP_HOST`, `SMTP_USER`, and `SMTP_PASS` before running it.

## 7. Set file permissions

The app writes to `rooms/` and `rooms_data/`.

```bash
cd /var/www/sketchboard
sudo mkdir -p rooms rooms_data
sudo chown -R www-data:sketchboard rooms rooms_data
sudo chmod -R 775 rooms rooms_data
sudo find rooms rooms_data -type f -exec chmod 664 {} \;
```

## 8. Configure Apache

Copy the included vhost example:

```bash
sudo cp deploy/apache-sketchboard.conf /etc/apache2/sites-available/sketchboard.conf
sudo nano /etc/apache2/sites-available/sketchboard.conf
```

Set `ServerName` and `DocumentRoot`, then enable the site:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite sketchboard.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 9. Enable HTTPS

Once DNS is live:

```bash
sudo certbot --apache -d your-domain.com -d www.your-domain.com
```

Certbot can add the HTTP to HTTPS redirect automatically.

## 10. Open firewall ports

If you are using UFW on the VM:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
```

Also make sure the GCP VPC firewall allows inbound `tcp:80` and `tcp:443`.

## 11. Verify the deployment

Run these checks:

```bash
php -m | grep -E 'pdo_mysql|openssl'
php -l auth.php
php -l bootstrap.php
php -l db.php
curl -I http://your-domain.com
curl -I https://your-domain.com
```

Optional SMTP test:

```bash
curl "https://your-domain.com/test_smtp.php?email=you@example.com"
```

## 12. Basic update flow

```bash
cd /var/www/sketchboard
sudo -u sketchboard git pull
sudo systemctl reload apache2
```

## Notes

- This app currently auto-creates its database tables on first request through `db.php`.
- Keep `config.php` out of git. It is already listed in `.gitignore`.
- If you later move MySQL off-box or put Apache behind a load balancer, the current structure can stay mostly unchanged.
