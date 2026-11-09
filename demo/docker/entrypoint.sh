#!/bin/bash
# Demo container start: database, Akeneo install (first start only), search index, demo setup,
# then the job queue worker and Apache. Variables: demo/.env.example.
# Passwords and keys are never printed.
set -euo pipefail
cd /srv/pim
echo "[demo] Starting…"
source /usr/local/lib/demo-env.sh

for attempt in $(seq 1 40); do
  if php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%d", getenv("APP_DATABASE_HOST"), getenv("APP_DATABASE_PORT")), getenv("APP_DATABASE_USER"), getenv("APP_DATABASE_PASSWORD"));
    $pdo->exec(sprintf("CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", getenv("APP_DATABASE_NAME")));
  ' 2>/tmp/db.log; then break; fi
  if [ "$attempt" = 40 ]; then echo "[demo] Database not reachable:"; cat /tmp/db.log; exit 1; fi
  echo "[demo] Database not ready yet, retrying…"; sleep 3
done

for attempt in $(seq 1 60); do
  if curl -sf -o /dev/null "http://${APP_INDEX_HOSTS#http://}/_cluster/health?wait_for_status=yellow&timeout=2s"; then break; fi
  if [ "$attempt" = 60 ]; then echo "[demo] Elasticsearch not reachable at $APP_INDEX_HOSTS."; exit 1; fi
  echo "[demo] Elasticsearch not ready yet, retrying…"; sleep 3
done

if [ -z "${APP_SECRET:-}" ]; then echo "[demo] APP_SECRET is not set (any long random string; keep it stable)."; exit 1; fi

mkdir -p public/media && chown -R www-data:www-data var public/media
installed=$(php -r '
  $pdo = new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s", getenv("APP_DATABASE_HOST"), getenv("APP_DATABASE_PORT"), getenv("APP_DATABASE_NAME")), getenv("APP_DATABASE_USER"), getenv("APP_DATABASE_PASSWORD"));
  echo $pdo->query("SHOW TABLES LIKE \"pim_catalog_product\"")->fetchColumn() ? "yes" : "no";
')
if [ "$installed" = "no" ]; then
  echo "[demo] First start: installing Akeneo PIM (takes a few minutes)…"
  if ! demo-console pim:installer:db --catalog vendor/akeneo/pim-community-dev/src/Akeneo/Platform/Bundle/InstallerBundle/Resources/fixtures/minimal \
      --no-interaction > /tmp/install.log 2>&1; then
    echo "[demo] Akeneo install failed:"; grep -viE 'password|secret' /tmp/install.log | tail -40; exit 1
  fi
  echo "[demo] Akeneo PIM installed."
else
  demo-console cache:clear --no-warmup > /dev/null
  # Elasticsearch keeps no data between deploys on Railway: recreate and refill the index if it is gone.
  if ! curl -sf -o /dev/null "http://${APP_INDEX_HOSTS#http://}/${APP_PRODUCT_AND_PRODUCT_MODEL_INDEX_NAME:-akeneo_pim_product_and_product_model}"; then
    echo "[demo] Search index missing: rebuilding it…"
    demo-console akeneo:elasticsearch:reset-indexes --reset-indexes --no-interaction > /tmp/index.log 2>&1 || { echo "[demo] Index reset failed:"; tail -20 /tmp/index.log; }
    demo-console pim:product-model:index --all > /dev/null 2>&1 || true
    demo-console pim:product:index --all > /dev/null 2>&1 || true
  fi
fi
demo-console cache:warmup > /dev/null
demo-console supertext:demo-setup

# Job queue worker (mass edits, imports and exports), restarted every hour.
(
  while true; do
    demo-console messenger:consume ui_job import_export_job data_maintenance_job --time-limit=3600 --memory-limit=512M >> /tmp/worker.log 2>&1 || sleep 5
  done
) &

# Exactly one Apache MPM (prefork, for mod_php); Railway's runtime otherwise reports more than one.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
sed -ri "s/Listen [0-9]+/Listen ${PORT:-8080}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf
echo "ServerName localhost" > /etc/apache2/conf-enabled/servername.conf
# Apache's PHP sees the same settings as the console.
for v in APP_ENV APP_DEBUG APP_SECRET APP_DATABASE_HOST APP_DATABASE_PORT APP_DATABASE_USER APP_DATABASE_PASSWORD APP_DATABASE_NAME \
  APP_INDEX_HOSTS APP_PRODUCT_AND_PRODUCT_MODEL_INDEX_NAME AKENEO_PIM_URL SUPERTEXT_API_KEY SUPERTEXT_API_URL SUPERTEXT_VERSION_REPORTS SUPERTEXT_REPORT_URL; do
  if [ -n "${!v:-}" ]; then printf 'PassEnv %s\n' "$v"; fi
done > /etc/apache2/conf-enabled/akeneo-env.conf
# SUPERTEXT_REVIEW_URL also when set but empty (= Translation review off for every locale).
if [ -n "${SUPERTEXT_REVIEW_URL+x}" ]; then echo 'PassEnv SUPERTEXT_REVIEW_URL' >> /etc/apache2/conf-enabled/akeneo-env.conf; fi
echo "[demo] Starting Apache on port ${PORT:-8080} (${AKENEO_PIM_URL})."
exec apache2-foreground
