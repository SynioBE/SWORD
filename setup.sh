#!/bin/bash

./vendor/bin/sail up -d --remove-orphans
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan tunnel:sync
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
