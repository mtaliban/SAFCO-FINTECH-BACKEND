#!/usr/bin/env bash
#
# SAFCO FINTECH LMS - EC2 Deploy Script
#
# Runs on the EC2 server (invoked by GitHub Actions over SSH).
# Called by the "Run deploy script on EC2" step in .github/workflows/deploy.yml.
#
# Assumes:
#   - docker + docker compose plugin installed (workflow does this)
#   - docker login already done (workflow does this)
#   - .env exists (workflow generates it from GitHub secrets)
#
# Usage:
#   sudo ./deploy.sh mtalibani/safco-backend sha-abc1234567
#
set -euo pipefail

DOCKER_IMAGE="${1:?Usage: deploy.sh <docker-image> <tag>}"
IMAGE_TAG="${2:?Usage: deploy.sh <docker-image> <tag>}"
COMPOSE_FILE="docker-compose.prod.yml"

# Use sudo for docker unless already running as root.
DOCKER="sudo docker"
if [ "$EUID" -eq 0 ]; then
    DOCKER="docker"
fi

echo "==> SAFCO deploy started"
echo "    Image: ${DOCKER_IMAGE}:${IMAGE_TAG}"
echo "    Dir:   $(pwd)"

# Sanity: required files.
for f in "${COMPOSE_FILE}" .env; do
    if [ ! -f "${f}" ]; then
        echo "!! Missing ${f} — cannot deploy." >&2
        exit 1
    fi
done

# Export image reference so docker-compose picks up the right tag.
export DOCKER_IMAGE IMAGE_TAG

# Stop running containers so their images become unused and can be pruned,
# then run aggressive cleanup to free disk space before pulling the new image.
echo "==> Pre-deploy cleanup (free disk space) ..."
$DOCKER compose -f "${COMPOSE_FILE}" down --remove-orphans 2>/dev/null || true
$DOCKER system prune -af 2>/dev/null || true

echo "==> Pulling image ${DOCKER_IMAGE}:${IMAGE_TAG} + latest ..."
$DOCKER compose -f "${COMPOSE_FILE}" pull app worker reverb scheduler

echo "==> Bringing up stack (rolling restart) ..."
$DOCKER compose -f "${COMPOSE_FILE}" up -d --remove-orphans

# Wait for MySQL healthcheck to pass.
echo "==> Waiting for MySQL to be healthy ..."
for i in $(seq 1 30); do
    status=$($DOCKER inspect --format='{{.State.Health.Status}}' safco-mysql 2>/dev/null || echo "starting")
    if [ "$status" = "healthy" ]; then
        echo "    MySQL healthy after ${i}0s."
        break
    fi
    sleep 10
done

# Small delay so the app container is fully wired.
sleep 5

echo "==> Running database migrations ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan migrate --force

echo "==> Seeding roles + permissions (idempotent) ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan db:seed --class=RoleAndPermissionSeeder --force

echo "==> Seeding organizations (idempotent) ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan db:seed --class=OrganizationSeeder --force

echo "==> Seeding admin + default trainer accounts (idempotent) ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan db:seed --class=AdminUserSeeder --force

echo "==> Seeding demo accounts — all 5 roles (idempotent) ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan db:seed --class=DemoAccountsSeeder --force

echo "==> Ensuring storage permissions ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

echo "==> Rebuilding Laravel caches ..."
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan config:cache
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan route:cache
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan event:cache
$DOCKER compose -f "${COMPOSE_FILE}" exec -T app php artisan storage:link || true

echo "==> Restarting queue worker to pick up new code ..."
$DOCKER compose -f "${COMPOSE_FILE}" restart worker

echo "==> Pruning dangling images ..."
$DOCKER image prune -f

echo "==> Deploy finished successfully."
