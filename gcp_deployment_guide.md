# Sketchboard 2 - GCP VM Instance SSH Deployment Guide

Follow this step-by-step procedure to deploy the application on a Google Cloud Platform (GCP) Compute Engine virtual machine running Debian or Ubuntu.

---

## 1. Create a VM Instance on GCP
1. Go to the **GCP Console** -> **Compute Engine** -> **VM Instances**.
2. Click **Create Instance**.
3. **Machine Configuration:** Select **e2-micro** (perfect for light workloads).
4. **Boot Disk:** Choose **Debian 12** or **Ubuntu 22.04 LTS** (Standard persistent disk, 10–20 GB).
5. **Firewall:** Check both:
   * **Allow HTTP traffic**
   * **Allow HTTPS traffic**
6. Click **Create**.

---

## 2. Connect via SSH
Connect to your VM using the `gcloud` command-line utility or via the "SSH" button next to your instance in the GCP console:
```bash
gcloud compute ssh --zone=YOUR_VM_ZONE YOUR_VM_NAME
```

---

## 3. Install Apache, PHP, and Git
Update the package index and install Apache web server, PHP (along with necessary database & encryption modules), and Git:
```bash
sudo apt update
sudo apt install -y apache2 php php-mysql php-mbstring php-xml php-curl php-json git
```

---

## 4. Install and Secure MySQL Server
1. Install the MySQL database server:
   ```bash
   sudo apt install -y mysql-server
   ```
2. Run the secure installation utility to configure basic security (root password, remove anonymous users, disable remote root login):
   ```bash
   sudo mysql_secure_installation
   ```

---

## 5. Configure MySQL Database
Log into the MySQL server as root:
```bash
sudo mysql -u root -p
```
Run the following SQL commands to create the database and user:
```sql
-- Create database
CREATE DATABASE sketchboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create production user and grant permissions
CREATE USER 'sketch_user'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD';
GRANT ALL PRIVILEGES ON sketchboard.* TO 'sketch_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 6. Deploy Codebase to Apache Root
1. Clean the default Apache web root folder:
   ```bash
   sudo rm -rf /var/www/html/*
   ```
2. Clone your repository into the web root:
   ```bash
   sudo git clone https://github.com/jasperorquiza-dev/sketchboard.git /var/www/html/
   ```
3. Set the correct ownership so the web server can read and write files:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/
   sudo chmod -R 755 /var/www/html/
   ```

---

## 7. Configure Production Settings
Create the configuration file:
```bash
sudo -u www-data nano /var/www/html/config.php
```
Add your MySQL configuration details:
```php
<?php
// Production Database Credentials (GCP MySQL)
$dbHost = 'localhost';
$dbUser = 'sketch_user';
$dbPass = 'YOUR_DB_PASSWORD';
$dbName = 'sketchboard';
```
Save and exit (`Ctrl + O`, `Enter`, `Ctrl + X`).

---

## 8. Configure Apache Settings
To ensure `.htaccess` rules (like CSRF blocks and folder restrictions) work correctly, Apache must be configured to allow configuration overrides:
1. Open the default site configuration:
   ```bash
   sudo nano /etc/apache2/sites-available/000-default.conf
   ```
2. Add the following block inside the `<VirtualHost *:80>` tag:
   ```apache
   <Directory /var/www/html>
       AllowOverride All
       Require all granted
   </Directory>
   ```
3. Enable the Apache rewrite module and restart the server:
   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

---

## 9. Install Let's Encrypt SSL (HTTPS)
Install Certbot to secure your traffic on `sketchboard.online`:
```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d sketchboard.online -d www.sketchboard.online
```
*(Follow the prompts to configure automatic renewal.)*
