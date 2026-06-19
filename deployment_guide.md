# Sketchboard 2 - Namecheap Stellar SSH Deployment Guide

Follow this step-by-step SSH procedure to deploy the application on Namecheap Stellar hosting for **sketchboard.online**.

---

## 1. Enable SSH Access in cPanel
1. Log in to your **Namecheap cPanel**.
2. Search for and click **SSH Access** (under the *Security* section).
3. Click **Manage SSH Keys** to generate a new key or upload your existing public key (`~/.ssh/id_rsa.pub`).
4. Click **Authorize** next to the key you added/generated.
5. Note the connection details:
   * **Host:** `sketchboard.online` (or your server's IP address)
   * **Port:** `21098` (Namecheap's custom SSH port)
   * **Username:** Your cPanel username

---

## 2. Connect via SSH
From your local terminal, run the following command to connect to your Namecheap server:
```bash
ssh -p 21098 your_cpanel_username@sketchboard.online
```

---

## 3. Clone the Repository
On your server, navigate to the web root directory (usually `public_html` for your primary domain):
```bash
cd ~/public_html
```
If the directory is empty or has placeholder files, remove them:
```bash
rm -f index.html index.php default.html
```
Clone your GitHub repository:
```bash
git clone https://github.com/jasperorquiza-dev/sketchboard.git .
```

---

## 4. Setup MySQL Database in cPanel
1. In cPanel, navigate to **MySQL Database Wizard**.
2. **Step 1: Create a Database:** Name it (e.g., `yourcpanel_sketch`). Click *Next*.
3. **Step 2: Create Database User:** Enter a username (e.g., `yourcpanel_user`) and generate a secure password. Click *Create User*.
4. **Step 3: Add User to Database:** Check **ALL PRIVILEGES** and click *Next Step*.

---

## 5. Configure Production Settings
Create the production `config.php` file on the server using `nano`:
```bash
nano config.php
```
Paste and fill in the database configuration:
```php
<?php
// Production Database Credentials
$dbHost = 'localhost';
$dbUser = 'your_cpanel_db_user'; // e.g. 'yourcpanel_user'
$dbPass = 'your_cpanel_db_password';
$dbName = 'your_cpanel_db_name'; // e.g. 'yourcpanel_sketch'
```
Save and exit `nano` by pressing `Ctrl + O`, then `Enter`, then `Ctrl + X`.

---

## 6. Secure the Rooms Data Folder
Verify that the `rooms_data/` directory exists and has the correct permissions:
```bash
mkdir -p rooms_data
chmod 750 rooms_data
```
The `.htaccess` file inside `rooms_data/` is already in the repository and will block direct browser downloads.

---

## 7. Enable HTTPS (SSL)
Ensure you enforce SSL to protect session cookies and data transmission:
1. In cPanel, search for **Namecheap SSL** or **Let's Encrypt** to install a certificate for `sketchboard.online`.
2. To redirect all HTTP traffic to HTTPS, create or append to the main `.htaccess` in `~/public_html/`:
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```
