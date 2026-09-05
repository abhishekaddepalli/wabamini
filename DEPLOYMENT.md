# 🚀 WabaMini - Complete Production VPS Deployment Runbook

This guide provides an exhaustive, step-by-step walkthrough to deploy **WabaMini** onto any Linux Virtual Private Server (VPS) running **Ubuntu 22.04 LTS** or **Ubuntu 24.04 LTS**.

Supported Cloud Providers: **DigitalOcean, Hetzner, AWS EC2, Linode, Vultr, Contabo, OVH**.

---

## 📋 Table of Contents
1. [Server Hardware & Prerequisites](#1-server-hardware--prerequisites)
2. [Step 1: VPS Initial Hardening & System Setup](#step-1-vps-initial-hardening--system-setup)
3. [Step 2: DNS & Domain Setup](#step-2-dns--domain-setup)
4. [Step 3: Dockerized Deployment (Recommended)](#step-3-dockerized-deployment-recommended)
5. [Step 4: SSL Certificate Setup via Certbot](#step-4-ssl-certificate-setup-via-certbot)
6. [Step 5: Database Seeding & Verification](#step-5-database-seeding--verification)
7. [Step 6: Native VPS Deployment (Nginx + Systemd + Supervisor)](#step-6-native-vps-deployment-alternative)
8. [Step 7: Automated Backups & Maintenance](#step-7-automated-backups--maintenance)
9. [Step 8: Zero-Downtime Update Script](#step-8-zero-downtime-update-script)
10. [Troubleshooting & FAQs](#troubleshooting--faqs)

---

## 1. Server Hardware & Prerequisites

| Specification | Minimum (Staging / Small Business) | Recommended (Production / SaaS) |
| :--- | :--- | :--- |
| **vCPU** | 2 vCPUs | 4 vCPUs |
| **RAM** | 4 GB | 8 GB |
| **Swap** | 4 GB Swap | 4 GB Swap |
| **Disk Space**| 40 GB NVMe / SSD | 80+ GB NVMe / SSD |
| **Operating System** | Ubuntu 22.04 / 24.04 LTS | Ubuntu 22.04 / 24.04 LTS |

---

## Step 1: VPS Initial Hardening & System Setup

Connect to your VPS via SSH as root:
```bash
ssh root@YOUR_SERVER_IP
```

### 1.1 Update System Packages
```bash
apt update && apt upgrade -y
apt install -y curl wget git ufw fail2ban unzip htop certbot python3-certbot-nginx
```

### 1.2 Create a Dedicated Deployer User
```bash
# Create user 'deploy'
adduser --gecos "" deploy
usermod -aG sudo deploy

# Copy SSH keys to new user
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

### 1.3 Configure a 4GB Swap Space (Crucial for Memory Spikes)
```bash
fallocate -l 4G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' >> /etc/sysctl.conf
sysctl -p
```

### 1.4 Configure UFW Firewall
```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw --force enable
ufw status verbose
```

---

## Step 2: DNS & Domain Setup

Go to your Domain Registrar or DNS Manager (Cloudflare, Namecheap, GoDaddy) and add the following **A Records**:

| Type | Host / Name | Value / Target | TTL |
| :--- | :--- | :--- | :--- |
| **A** | `app` | `YOUR_SERVER_IP` | 1 min / Auto |
| **A** | `api` | `YOUR_SERVER_IP` | 1 min / Auto |

*Note: If using Cloudflare, keep the proxy status **DNS only (grey cloud)** during initial SSL issuance.*

---

## Step 3: Dockerized Deployment (Recommended)

Switch to the `deploy` user:
```bash
su - deploy
```

### 3.1 Install Docker & Docker Compose
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
newgrp docker
docker --version && docker compose version
```

### 3.2 Clone the WabaMini Repository
```bash
sudo mkdir -p /var/www/wabamini
sudo chown -R deploy:deploy /var/www/wabamini
git clone https://github.com/abhishekaddepalli/wabamini.git /var/www/wabamini
cd /var/www/wabamini
```

### 3.3 Configure Environment Variables
Generate secure credentials:
```bash
# Generate random 32-character secrets
openssl rand -hex 16
```

Create `/var/www/wabamini/.env.production`:
```bash
cat << 'EOF' > /var/www/wabamini/.env.production
# ==========================================
# WabaMini Production Environment Configuration
# ==========================================

DOMAIN=yourdomain.com
APP_NAME=WabaMini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain.com
API_URL=https://api.yourdomain.com

# Database Credentials
DB_DATABASE=whatsomni
DB_USERNAME=whatsomni
DB_PASSWORD=YOUR_STRONG_DB_PASSWORD_HERE
DB_ROOT_PASSWORD=YOUR_STRONG_ROOT_PASSWORD_HERE

# Redis
REDIS_PASSWORD=YOUR_STRONG_REDIS_PASSWORD_HERE

# Laravel Application Key (Run php artisan key:generate)
APP_KEY=base64:YOUR_GENERATED_LARAVEL_KEY_HERE

# Reverb WebSockets
REVERB_APP_ID=wabamini_prod_id
REVERB_APP_KEY=wabamini_prod_key
REVERB_APP_SECRET=wabamini_prod_secret
REVERB_HOST=api.yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https

# Internal Worker Authentication
INTERNAL_API_SECRET=YOUR_INTERNAL_SECRET_HERE
EOF
```

Copy sub-service environments:
```bash
# Backend Environment
cp backend/.env.example backend/.env
# Frontend Environment
cp frontend/.env.example frontend/.env.local
# Worker Environment
cp baileys-worker/.env.example baileys-worker/.env
```

---

## Step 4: SSL Certificate Setup via Certbot

Before starting Nginx with SSL, obtain valid SSL certificates from Let's Encrypt:

```bash
# Stop any temporary web server
sudo systemctl stop nginx 2>/dev/null || true

# Obtain certificates using Certbot standalone mode
sudo certbot certonly --standalone \
  -d app.yourdomain.com \
  -d api.yourdomain.com \
  --agree-tos \
  -m your-email@yourdomain.com \
  --non-interactive
```

The certificates will be generated in `/etc/letsencrypt/live/app.yourdomain.com/`.

---

## Step 5: Build & Launch the Production Stack

From `/var/www/wabamini`:

```bash
# Build and launch all containers in detached mode
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build
```

Verify that all containers are healthy:
```bash
docker compose -f docker-compose.prod.yml ps
```

Expected containers:
- `wabamini_mysql` (Healthy)
- `wabamini_redis` (Healthy)
- `wabamini_backend` (Up)
- `wabamini_queue` (Up)
- `wabamini_reverb` (Up)
- `wabamini_scheduler` (Up)
- `wabamini_baileys` (Up)
- `wabamini_frontend` (Up)
- `wabamini_nginx` (Up)

### Run Database Migrations & Initial Setup
```bash
# Execute Laravel migrations
docker exec -it wabamini_backend php artisan migrate --force

# Seed default Super Admin credentials
docker exec -it wabamini_backend php artisan db:seed --class=DatabaseSeeder --force

# Create storage symlink
docker exec -it wabamini_backend php artisan storage:link

# Cache production config and routes
docker exec -it wabamini_backend php artisan config:cache
docker exec -it wabamini_backend php artisan route:cache
docker exec -it wabamini_backend php artisan view:cache
```

Now access your production platform:
- **Client & Admin Portal**: `https://app.yourdomain.com`
- **Super Admin Sign In**: `https://app.yourdomain.com/superadmin/login`
- **REST API Healthcheck**: `https://api.yourdomain.com/api/health`

---

## Step 6: Native VPS Deployment (Alternative)

If you prefer to run services natively without Docker containers:

### 6.1 Install PHP 8.3 & Required Extensions
```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-bcmath php8.3-intl php8.3-redis \
  php8.3-opcache php8.3-pcntl php8.3-posix
```

### 6.2 Install Node.js 20 & PM2
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
```

### 6.3 Setup Supervisor for Queues & WebSockets
Install supervisor:
```bash
sudo apt install -y supervisor
```

Create `/etc/supervisor/conf.d/wabamini.conf`:
```ini
[program:wabamini-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wabamini/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/wabamini/backend/storage/logs/queue.log

[program:wabamini-reverb]
command=php /var/www/wabamini/backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=deploy
redirect_stderr=true
stdout_logfile=/var/www/wabamini/backend/storage/logs/reverb.log
```

Update supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### 6.4 Setup Cron for Laravel Scheduler
```bash
(crontab -l 2>/dev/null; echo "* * * * * cd /var/www/wabamini/backend && php artisan schedule:run >> /dev/null 2>&1") | crontab -
```

---

## Step 7: Automated Backups & Maintenance

Create an automated daily MySQL backup script at `/var/www/wabamini/scripts/backup.sh`:

```bash
mkdir -p /var/www/wabamini/scripts /var/backups/wabamini
cat << 'EOF' > /var/www/wabamini/scripts/backup.sh
#!/bin/bash
BACKUP_DIR="/var/backups/wabamini"
DATE=$(date +"%Y%m%d_%H%M%S")
FILENAME="$BACKUP_DIR/wabamini_db_$DATE.sql.gz"

mkdir -p $BACKUP_DIR

# Dump and compress database from container
docker exec wabamini_mysql mysqldump -uwhatsomni -pYOUR_STRONG_DB_PASSWORD_HERE whatsomni | gzip > $FILENAME

# Keep only the last 7 days of backups
find $BACKUP_DIR -type f -name "*.sql.gz" -mtime +7 -exec rm {} \;

echo "[$(date)] Backup completed successfully: $FILENAME"
EOF

chmod +x /var/www/wabamini/scripts/backup.sh
```

Add to cron to execute every midnight:
```bash
(crontab -l 2>/dev/null; echo "0 0 * * * /var/www/wabamini/scripts/backup.sh >> /var/log/wabamini_backup.log 2>&1") | crontab -
```

---

## Step 8: Zero-Downtime Update Script

Create a zero-downtime deployment script `/var/www/wabamini/deploy.sh`:

```bash
cat << 'EOF' > /var/www/wabamini/deploy.sh
#!/bin/bash
set -e

echo "🚀 Starting WabaMini Deployment..."
cd /var/www/wabamini

# 1. Fetch latest changes
git pull origin main

# 2. Rebuild and restart containers
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build

# 3. Run database migrations
docker exec -t wabamini_backend php artisan migrate --force

# 4. Clear and rebuild caches
docker exec -t wabamini_backend php artisan optimize:clear
docker exec -t wabamini_backend php artisan config:cache
docker exec -t wabamini_backend php artisan route:cache
docker exec -t wabamini_backend php artisan view:cache

# 5. Restart queue workers
docker exec -t wabamini_backend php artisan queue:restart

echo "✅ WabaMini deployment completed successfully!"
EOF

chmod +x /var/www/wabamini/deploy.sh
```

Now, future updates can be rolled out with a single command:
```bash
./deploy.sh
```

---

## ❓ Troubleshooting & FAQs

### 1. `502 Bad Gateway` on API or WebSockets
- **Check Backend Logs**: `docker logs -f wabamini_backend`
- **Check Nginx Configuration**: Verify upstream names in `/etc/nginx/conf.d/default.conf` match your service names.

### 2. WhatsApp Baileys Worker QR Code not generating
- **Check Worker Logs**: `docker logs -f wabamini_baileys`
- **Verify Port**: Ensure port `5001` is open to internal docker network `wabamini_network`.

### 3. File Uploads failing (Max Size)
- In Nginx configuration, verify `client_max_body_size 100M;`.
- In `backend/docker/php.ini`, verify `upload_max_filesize = 64M` and `post_max_size = 64M`.

---

<div align="center">
  <b>Need help deploying?</b> Open an issue on GitHub at <a href="https://github.com/abhishekaddepalli/wabamini">abhishekaddepalli/wabamini</a>.
</div>
