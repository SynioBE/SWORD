#!/bin/bash

set -eu

echo "=> Starting SWORD development environment..."

echo "=> Install composer dependencies via docker"

docker run \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

if [[ ! -f .env ]]; then
    cp .env.example .env
else
    echo "=> .env already exists - skipping"
fi

echo "=> Starting up containers"
./vendor/bin/sail up -d

echo "=> Generating keys"
./vendor/bin/sail artisan key:generate

echo "=> Migrate fresh"
./vendor/bin/sail artisan migrate:fresh --seed

echo "=> Install NPM dependencies"

./vendor/bin/sail npm install

echo "=> Final - run dev"

./vendor/bin/sail npm run dev

