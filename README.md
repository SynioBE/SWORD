# How to run this project

After cloning the repository and moving into the project directory, run the following commands.

**You need Docker to work on this project in the way outlined below.** If you prefer to work without Docker, it is possible, but you'll have to set up everything yourself.

## Install the required dependencies via Docker

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

The defaults should be fine in most cases.

## Start the containers and initialize the DB

```shell
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate:fresh --seed
```

# Shortcuts
```shell
# php artisan migrate:fresh --seed
composer mfs 
# php artisan migrate:fresh
composer mf

```

## Install NPM dependencies

```shell
./vendor/bin/sail npm install
```

## Start Vite with Hot Module Reloading

```shell
./vendor/bin/sail npm run dev
```

The above steps are performed by the [setup](setup.sh) script.

## Connect to the Laravel application

Open your browser and go to the URL specified in the .env file as `APP_URL`, or simply go to `http://localhost`.

You can then login using the test user `test@example.com` with password `password`.

## Manage the database
Open your browser and go to the URL specified in the .env file as `APP_URL` with port `8080`, or simply go to `http://localhost:8080`. This will open Adminer.

Login using the database credentials in the `.env` file.

Now you can visually inspect & manage your local database.

## Provisioning a server

To provision a server, the server needs to talk to your application. Because you are running locally, this is difficult.

But we can use a `cloudflared` tunnel for this. Such a tunnel is already running as part of the Docker Compose stack.

Before provisioning a server, you have to run `./vendor/bin/sail artisan tunnel:sync` to update the `APP_URL` based on the current `cloudflared` tunnel that has been created (the hostname will change randomly each time the container is restarted). After that, the generated provisioning script will contain the correct tunneled URL.


# Cloudflare Integration

Configure one or more Cloudflare accounts under **Settings → Integrations**. Both API Token and Global API Key auth are supported.

## DNS record operations (PHP)

```php
use App\Services\Cloudflare\CloudflareService;
use App\Actions\Cloudflare\UpsertCloudflareDnsRecord;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

// Build the service from an Integration model
$service = new CloudflareService(
    httpClient: app(ClientInterface::class),
    httpFactory: new HttpFactory,
    credentials: $integration->credentials,
);

// Auto-detect the zone from a domain
$zone   = $service->findZoneForDomain($site->domain);
$zoneId = $zone['id'];

// Upsert (create or update) — recommended for site creation
(new UpsertCloudflareDnsRecord)->handle(
    service: $service,
    zoneId: $zoneId,
    name: $site->domain,
    type: 'A',               // 'A', 'CNAME', or 'both'
    content: $server->ip_address,
    proxied: true,
    ttl: 1,                  // 1 = Auto
    cnameContent: null,      // required when type = 'both'
);

// Delete by record ID
$service->deleteDnsRecord(zoneId: $zoneId, recordId: 'rec-abc123');
```
