# 🚀 WabaMini - Ubuntu 24.04 LTS Production VPS Deployment Guide

This guide is the definitive production deployment manual for **WabaMini** on a standardized **Ubuntu 24.04 LTS** VPS architecture.

---

## 🏛️ Standardized Production Architecture

- **Operating System**: Ubuntu 24.04 LTS (Noble Numbat)
- **Target Hardware**: 8 vCPU, 16 GB RAM, 400 GB SSD
- **Directory Layout**:
  - Applications Root: `/www/apps/wabamini`
  - Automated Backups: `/www/backups/wabamini`
  - Deployment Automation: `/www/deploy`
- **User & Group**: `www-data:www-data`
- **Public Firewall Ports (UFW)**:
  - `22123` → SSH (Hardened custom port)
  - `80` → HTTP (ACME challenge & HTTPS redirect)
  - `443` → HTTPS (Public SSL entry point)
- **Internal Localhost Bindings (Never Publicly Exposed)**:
  - `127.0.0.1:3100` → Next.js Frontend Server
  - `127.0.0.1:3101` → Baileys WhatsApp Microservice Worker
  - `127.0.0.1:8080` → Laravel Reverb WebSockets
  - `127.0.0.1:3306` → MySQL 8.0 Database Server
  - `127.0.0.1:6379` → Redis 7 In-Memory Store
  - `/run/php/php8.3-fpm.sock` → PHP 8.3 FastCGI Unix Socket
- **Process Management**: Native **systemd** (No Docker, No PM2, No aaPanel/Coolify)

---

## 📋 Table of Contents
1. [Prerequisites & Package Installation](#1-prerequisites--package-installation)
2. [Firewall (UFW) & Security Setup](#2-firewall-ufw--security-setup)
3. [Directory Layout & Base Structure](#3-directory-layout--base-structure)
4. [Database & Redis Configuration](#4-database--redis-configuration)
5. [Codebase Deployment & Permissions](#5-codebase-deployment--permissions)
6. [Environment Configuration (.env Files)](#6-environment-configuration-env-files)
7. [Dependencies Build & Setup](#7-dependencies-build--setup)
8. [Systemd Service Setup](#8-systemd-service-setup)
9. [Nginx Reverse Proxy & SSL Configuration](#9-nginx-reverse-proxy--ssl-configuration)
10. [Database Migrations & Initial Seeding](#10-database-migrations--initial-seeding)
11. [Scheduler & Cron Configuration](#11-scheduler--cron-configuration)
12. [Deployment & Backup Scripts](#12-deployment--backup-scripts)
13. [Verification & Health Checks](#13-verification--health-checks)

---

## 1. Prerequisites & Package Installation

Update your Ubuntu 24.04 packages and install PHP 8.3, Node.js 20/22 LTS, Nginx, MySQL, Redis, and Certbot:

```bash
# 1. Update OS package lists
sudo apt update && sudo apt upgrade -y

# 2. Install base system utilities
sudo apt install -y curl wget git unzip htop ufw fail2ban certbot python3-certbot-nginx logrotate net-tools

# 3. Install PHP 8.3 & Required Extensions
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd \
    php8.3-mbstring php8.3-xml php8.3-zip php8.3-bcmath php8.3-soap \
    php8.3-intl php8.3-readline php8.3-redis

# 4. Install Composer
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# 5. Install Node.js LTS (v20 or v22)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# 6. Install MySQL & Redis
sudo apt install -y mysql-server redis-server nginx
sudo systemctl enable --now mysql redis-server nginx php8.3-fpm
```

---

## 2. Firewall (UFW) & Security Setup

Ensure your SSH daemon is running on port **22123** before enabling UFW:

```bash
# Allow only custom SSH, HTTP, and HTTPS
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22123/tcp comment "SSH"
sudo ufw allow 80/tcp comment "HTTP / ACME"
sudo ufw allow 443/tcp comment "HTTPS"

# Enable Firewall
sudo ufw --force enable
sudo ufw status verbose
```

Verify that MySQL and Redis listen **only** on `127.0.0.1`:
- `/etc/mysql/mysql.conf.d/mysqld.cnf` → `bind-address = 127.0.0.1`
- `/etc/redis/redis.conf` → `bind 127.0.0.1 ::1`

---

## 3. Directory Layout & Base Structure

Create the standardized `/www` directory structure owned by `www-data`:

```bash
sudo mkdir -p /www/apps/wabamini
sudo mkdir -p /www/backups/wabamini
sudo mkdir -p /www/deploy

sudo chown -R www-data:www-data /www
sudo chmod -R 755 /www
```

---

## 4. Database & Redis Configuration

Log in to MySQL as root and create the dedicated database and non-root user:

```bash
sudo mysql -u root
```

Execute the following SQL commands (replace `SECURE_PASSWORD_HERE` with a strong 32-character password):

```sql
CREATE DATABASE whatsomni CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wabamini_user'@'127.0.0.1' IDENTIFIED BY 'SECURE_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON whatsomni.* TO 'wabamini_user'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

---

## 5. Codebase Deployment & Permissions

Clone the repository into `/www/apps/wabamini`:

```bash
cd /www/apps
sudo -u www-data git clone https://github.com/abhishekaddepalli/wabamini.git wabamini
cd /www/apps/wabamini
```

---

## 6. Environment Configuration (.env Files)

Copy and customize the `.env` files for each component:

### 6.1 Backend (`/www/apps/wabamini/backend/.env`)
```bash
sudo -u www-data cp backend/.env.example backend/.env
```
Key settings to configure in `backend/.env`:
```ini
APP_NAME=WabaMini
APP_ENV=production
APP_KEY=                      # Will be generated in Step 7
APP_DEBUG=false
APP_URL=https://yourdomain.com
FRONTEND_URL=https://yourdomain.com
SANCTUM_STATEFUL_DOMAINS=yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsomni
DB_USERNAME=wabamini_user
DB_PASSWORD=SECURE_PASSWORD_HERE

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1

REVERB_APP_ID=wabamini_app_id
REVERB_APP_KEY=wabamini_reverb_key
REVERB_APP_SECRET=generate_reverb_secret_32_chars
REVERB_HOST="127.0.0.1"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="yourdomain.com"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

BAILEYS_WORKER_URL=http://127.0.0.1:3101
BAILEYS_SECRET_TOKEN=generate_baileys_secret_32_chars
INTERNAL_API_SECRET=generate_baileys_secret_32_chars
```

### 6.2 Frontend (`/www/apps/wabamini/frontend/.env`)
```bash
sudo -u www-data cp frontend/.env.example frontend/.env
```
Key settings in `frontend/.env`:
```ini
PORT=3100
HOST=127.0.0.1
NODE_ENV=production
NEXT_PUBLIC_BACKEND_URL=https://yourdomain.com

NEXT_PUBLIC_REVERB_APP_KEY=wabamini_reverb_key
NEXT_PUBLIC_REVERB_HOST=yourdomain.com
NEXT_PUBLIC_REVERB_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=https
```

### 6.3 Baileys Worker (`/www/apps/wabamini/baileys-worker/.env`)
```bash
sudo -u www-data cp baileys-worker/.env.example baileys-worker/.env
```
Key settings in `baileys-worker/.env`:
```ini
HOST=127.0.0.1
PORT=3101
LARAVEL_API_URL=https://yourdomain.com
BAILEYS_SECRET_TOKEN=generate_baileys_secret_32_chars
INTERNAL_API_SECRET=generate_baileys_secret_32_chars
```

---

## 7. Dependencies Build & Setup

Run installation steps as `www-data`:

```bash
# 1. Backend Dependencies & Keys
cd /www/apps/wabamini/backend
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan storage:link

# 2. Baileys Worker Dependencies
cd /www/apps/wabamini/baileys-worker
sudo -u www-data mkdir -p storage/sessions
sudo -u www-data npm install --omit=dev

# 3. Frontend Build
cd /www/apps/wabamini/frontend
sudo -u www-data npm install --omit=dev
sudo -u www-data npm run build

# 4. Strict Permissions
sudo chown -R www-data:www-data /www/apps/wabamini
sudo chmod -R 755 /www/apps/wabamini
sudo chmod -R 775 /www/apps/wabamini/backend/storage /www/apps/wabamini/backend/bootstrap/cache /www/apps/wabamini/baileys-worker/storage
```

---

## 8. Systemd Service Setup

The repository provides production unit templates in the `systemd/` directory:

```bash
# Copy systemd unit files to system folder
sudo cp /www/apps/wabamini/systemd/wabamini-frontend.service /etc/systemd/system/
sudo cp /www/apps/wabamini/systemd/wabamini-baileys.service /etc/systemd/system/
sudo cp /www/apps/wabamini/systemd/wabamini-reverb.service /etc/systemd/system/
sudo cp /www/apps/wabamini/systemd/wabamini-queue.service /etc/systemd/system/
sudo cp /www/apps/wabamini/systemd/wabamini-scheduler.service /etc/systemd/system/
sudo cp /www/apps/wabamini/systemd/wabamini-scheduler.timer /etc/systemd/system/

# Reload systemd daemon
sudo systemctl daemon-reload

# Enable and start all services
sudo systemctl enable --now wabamini-frontend
sudo systemctl enable --now wabamini-baileys
sudo systemctl enable --now wabamini-reverb
sudo systemctl enable --now wabamini-queue
sudo systemctl enable --now wabamini-scheduler.timer
```

Check statuses:
```bash
sudo systemctl status wabamini-frontend wabamini-baileys wabamini-reverb wabamini-queue wabamini-scheduler.timer
```

---

## 9. Nginx Reverse Proxy & SSL Configuration

### 9.1 Acquire SSL with Certbot
Before activating the full SSL block, obtain the Let's Encrypt certificate:
```bash
sudo certbot certonly --nginx -d yourdomain.com -d www.yourdomain.com
```

### 9.2 Install WabaMini Nginx Configuration
```bash
# Copy the single-domain config template
sudo cp /www/apps/wabamini/nginx/wabamini-single-domain.conf /etc/nginx/sites-available/wabamini.conf

# Replace placeholder domain with your actual domain
sudo sed -i 's/yourdomain.com/YOUR_ACTUAL_DOMAIN/g' /etc/nginx/sites-available/wabamini.conf

# Enable site and disable default
sudo ln -sf /etc/nginx/sites-available/wabamini.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

# Test Nginx syntax and reload
sudo nginx -t && sudo systemctl reload nginx
```

---

## 10. Database Migrations & Initial Seeding

Run database migrations and seed default Super Admin credentials:

```bash
cd /www/apps/wabamini/backend
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force
```

### Default Super Admin Credentials:
- **URL**: `https://yourdomain.com/saas-admin`
- **Email**: `admin@whatsomni.com`
- **Password**: `Password123!`

> [!CAUTION]
> Log into the Super Admin panel immediately after deployment and update this password.

---

## 11. Scheduler & Cron Configuration

Laravel's scheduler is managed either via the systemd timer installed in Step 8 (`wabamini-scheduler.timer`), or via cron:

```bash
# Cron alternative (if not using systemd timer):
sudo -u www-data crontab -e
```
Add the following entry:
```cron
* * * * * cd /www/apps/wabamini/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 12. Deployment & Backup Scripts

Copy automation scripts into `/www/deploy`:

```bash
sudo cp /www/apps/wabamini/scripts/deploy.sh /www/deploy/deploy-wabamini.sh
sudo cp /www/apps/wabamini/scripts/backup.sh /www/deploy/backup-wabamini.sh
sudo chmod +x /www/deploy/*.sh
```

### Automated Daily Backups
Add to root crontab:
```bash
sudo crontab -e
```
Add daily backup at 2:00 AM:
```cron
0 2 * * * /www/deploy/backup-wabamini.sh >> /var/log/wabamini_backup.log 2>&1
```

---

## 13. Verification & Health Checks

Test all services directly on localhost:

```bash
# 1. Frontend Health Check
curl -s http://127.0.0.1:3100/healthz

# 2. Baileys Worker Health Check
curl -s http://127.0.0.1:3101/health

# 3. Backend Health Check
curl -s https://yourdomain.com/up

# 4. Reverb WebSocket Port
nc -zv 127.0.0.1 8080

# 5. Service logs
sudo journalctl -u wabamini-frontend -n 20 --no-pager
sudo journalctl -u wabamini-baileys -n 20 --no-pager
sudo journalctl -u wabamini-reverb -n 20 --no-pager
sudo journalctl -u wabamini-queue -n 20 --no-pager
```
