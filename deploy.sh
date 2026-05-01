#!/usr/bin/env bash

set -euo pipefail

# =========================
# CONFIG
# =========================
APP_PATH="/var/www/momento.sardarit.cloud"
REMOTE_USER="root"
REMOTE_HOST="72.61.112.137"
SSH_KEY="~/.ssh/id_rsa"

ARCHIVE="deploy.tar.gz"

echo "======================================"
echo "🚀 STARTING DEPLOYMENT PIPELINE"
echo "======================================"

# =========================
# STEP 1: TEST VPS CONNECTION
# =========================
echo ""
echo "🔌 STEP 1: Testing VPS SSH connection..."

if ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no \
  "$REMOTE_USER@$REMOTE_HOST" "echo 'SSH OK'"; then
  echo "✅ VPS connection successful"
else
  echo "❌ VPS connection failed — aborting deployment"
  exit 1
fi

# =========================
# STEP 2: BUILD PROJECT
# =========================
echo ""
echo "📦 STEP 2: Installing dependencies & building assets..."

composer install --no-dev --optimize-autoloader

npm ci
# npm run build

echo "✅ Build completed successfully"

# =========================
# STEP 3: CREATE ARCHIVE
# =========================
echo ""
echo "🗜️ STEP 3: Creating deployment archive..."

tar --exclude='.git' \
    --exclude='node_modules' \
    --exclude='.env' \
    --exclude='storage/logs' \
    --exclude='storage/framework/cache' \
    --exclude='storage/framework/sessions' \
    --exclude='storage/framework/views' \
    -czf "$ARCHIVE" .

echo "✅ Archive created: $ARCHIVE"

# =========================
# STEP 4: UPLOAD TO VPS
# =========================
echo ""
echo "📤 STEP 4: Uploading archive to VPS..."

scp -i "$SSH_KEY" "$ARCHIVE" \
  "$REMOTE_USER@$REMOTE_HOST:/tmp/$ARCHIVE"

echo "✅ Upload completed"

# =========================
# STEP 5: DEPLOY ON VPS
# =========================
echo ""
echo "🖥️ STEP 5: Running deployment on VPS..."

ssh -i "$SSH_KEY" "$REMOTE_USER@$REMOTE_HOST" << EOF
set -e

echo "--------------------------------------"
echo "📁 Entering application directory"
echo "--------------------------------------"

mkdir -p "$APP_PATH"
cd "$APP_PATH"

echo "📦 Extracting files..."
tar -xzf /tmp/$ARCHIVE
rm -f /tmp/$ARCHIVE

echo "--------------------------------------"
echo "⚙️ Setting up environment"
echo "--------------------------------------"

if [ ! -f .env ]; then
  echo "⚠️ .env not found, copying from example"
  cp .env.example .env
fi

echo "🔑 Generating app key (if needed)..."
php artisan key:generate --force || true

echo "--------------------------------------"
echo "🗄️ Running database migrations"
echo "--------------------------------------"

php artisan migrate --force

echo "--------------------------------------"
echo "⚡ Optimizing Laravel"
echo "--------------------------------------"

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan storage:link || true

echo "--------------------------------------"
echo "🔐 Fixing permissions"
echo "--------------------------------------"

chmod -R 775 storage bootstrap/cache

echo "--------------------------------------"
echo "🔄 Restarting services"
echo "--------------------------------------"

sudo systemctl reload php8.3-fpm || true
sudo systemctl reload nginx || true

echo "🎉 Deployment finished successfully!"
EOF

# =========================
# STEP 6: CLEANUP LOCAL
# =========================
echo ""
echo "🧹 STEP 6: Cleaning local archive..."

rm -f "$ARCHIVE"

echo "======================================"
echo "✅ DEPLOYMENT COMPLETED SUCCESSFULLY"
echo "======================================"