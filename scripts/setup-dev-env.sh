#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../docker"

if [ ! -d freescout ]; then
    git clone --depth 1 https://github.com/freescout-help-desk/freescout.git freescout
fi

if [ ! -f freescout/.env ]; then
    cp freescout/.env.example freescout/.env
    sed -i 's/^DB_HOST=.*/DB_HOST=db/' freescout/.env
    sed -i 's/^DB_DATABASE=.*/DB_DATABASE=freescout/' freescout/.env
    sed -i 's/^DB_USERNAME=.*/DB_USERNAME=freescout/' freescout/.env
    sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=freescout/' freescout/.env
    sed -i 's#^APP_URL=.*#APP_URL=http://localhost:8080#' freescout/.env
    echo 'APP_TRUSTED_HOSTS=localhost' >> freescout/.env
fi

docker compose build
docker compose up -d
docker compose exec app php artisan key:generate --force

echo ""
echo "FreeScout core is up. Finish setup by visiting http://localhost:8080/install in your browser."
echo "DB connection inside the container: host=db user=freescout password=freescout database=freescout"
