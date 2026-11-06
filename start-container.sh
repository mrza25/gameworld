#!/bin/bash

# Railpack runs this file when the container starts on Railway. It replaces
# Railpack's default Laravel script, adding the marked steps.

set -e

# Added: the config cached at build time would hide variables changed since then.
php artisan config:clear

# Added: the volume mounted on storage/app starts out empty.
mkdir -p storage/app/public storage/app/private

php artisan migrate --force

# Added: set SEED_DATABASE=true for the first deploy only.
if [ "$SEED_DATABASE" = "true" ]; then
  php artisan db:seed --force
fi

php artisan storage:link
php artisan optimize:clear
php artisan optimize

# Added: payment deadlines and the other scheduled commands.
php artisan schedule:work &

echo "Starting Laravel server ..."

docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
