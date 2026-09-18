#!/usr/bin/env bash
# ==============================================================================
# WabaMini - Automated Production Backup Script
# Backup Destination: /www/backups/wabamini
# Retention Policy: 14 days
# ==============================================================================

set -euo pipefail

BACKUP_DIR="/www/backups/wabamini"
APP_DIR="/www/apps/wabamini"
DATE=$(date +"%Y%m%d_%H%M%S")
TARGET_DIR="${BACKUP_DIR}/${DATE}"

mkdir -p "${TARGET_DIR}"

echo "=========================================================="
echo " Starting WabaMini Backup at $(date)"
echo " Destination: ${TARGET_DIR}"
echo "=========================================================="

# 1. Source Database Credentials from backend/.env
if [ -f "${APP_DIR}/backend/.env" ]; then
    DB_NAME=$(grep -E '^DB_DATABASE=' "${APP_DIR}/backend/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    DB_USER=$(grep -E '^DB_USERNAME=' "${APP_DIR}/backend/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    DB_PASS=$(grep -E '^DB_PASSWORD=' "${APP_DIR}/backend/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    DB_HOST=$(grep -E '^DB_HOST=' "${APP_DIR}/backend/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    DB_PORT=$(grep -E '^DB_PORT=' "${APP_DIR}/backend/.env" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
else
    echo "ERROR: ${APP_DIR}/backend/.env not found."
    exit 1
fi

DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}

# 2. Database Backup (MySQL)
echo "--> Backing up MySQL database (${DB_NAME})..."
mysqldump -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASS}" \
    --single-transaction --quick --routines --triggers "${DB_NAME}" | gzip > "${TARGET_DIR}/database_${DB_NAME}.sql.gz"

# 3. Environment Files Backup
echo "--> Backing up environment files..."
mkdir -p "${TARGET_DIR}/env"
cp -p "${APP_DIR}/backend/.env" "${TARGET_DIR}/env/backend.env" || true
cp -p "${APP_DIR}/frontend/.env" "${TARGET_DIR}/env/frontend.env" || true
cp -p "${APP_DIR}/baileys-worker/.env" "${TARGET_DIR}/env/baileys-worker.env" || true

# 4. Storage & Media Backup
echo "--> Backing up uploaded storage and Baileys session credentials..."
if [ -d "${APP_DIR}/backend/storage/app" ]; then
    tar -czf "${TARGET_DIR}/backend_storage.tar.gz" -C "${APP_DIR}/backend/storage" app
fi

if [ -d "${APP_DIR}/baileys-worker/storage" ]; then
    tar -czf "${TARGET_DIR}/baileys_sessions.tar.gz" -C "${APP_DIR}/baileys-worker" storage
fi

# 5. Set strict permissions on backup directory
chmod -R 600 "${TARGET_DIR}"/*
chmod 700 "${TARGET_DIR}"

# 6. Apply retention policy (Prune backups older than 14 days)
echo "--> Pruning backups older than 14 days..."
find "${BACKUP_DIR}" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +

echo "=========================================================="
echo " Backup completed successfully at $(date)"
echo " Backup size: $(du -sh "${TARGET_DIR}" | cut -f1)"
echo "=========================================================="
