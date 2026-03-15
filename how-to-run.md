# How to run this project

After cloning the repository and moving into the project directory, run the following commands.

## Init 'sail'

```shell
docker run \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

## Configure .env

Copy `.env.example` to `.env` and configure it.
Please note that the `APP_URL` and the `APP_PORT` must be in sync.

## Start the containers and initialize the DB

```shell
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

## Enter the Laravel container
```shell
docker compose exec laravel.test bash
```

## Start Vite
From inside the Laravel container
```shell
npm install && npm run dev
```
## Connect to the Laravel application
Open your browser and go to the URL specified in the .env file as `APP_URL`.

You can then create a user account using "Sign Up".

