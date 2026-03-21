!#/bin/bash

./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail tunnel:sync
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
