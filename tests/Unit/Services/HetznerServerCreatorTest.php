<?php

use App\Services\Hetzner\CreateHetznerServerData;
use App\Services\Hetzner\HetznerServerCreator;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

test('HetznerServerCreator uploads a raw SSH key and returns server details', function () {
    $client = new class([new Response(201, [], json_encode(['ssh_key' => ['id' => 44, 'name' => 'uploaded-key']])), new Response(201, [], json_encode(['server' => ['id' => 999, 'name' => 'sword-web', 'status' => 'initializing', 'server_type' => ['name' => 'cx22'], 'datacenter' => ['location' => ['name' => 'nbg1']]]])), new Response(200, [], json_encode(['server' => ['public_net' => ['ipv4' => ['ip' => '203.0.113.10']]]]))]) implements ClientInterface
    {
        /** @var array<int, RequestInterface> */
        public array $requests = [];

        /**
         * @param  array<int, ResponseInterface>  $responses
         */
        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return array_shift($this->responses);
        }
    };

    $creator = new HetznerServerCreator($client, new HttpFactory);

    $result = $creator->create(new CreateHetznerServerData(
        apiKey: 'test-token',
        name: 'sword-web',
        location: 'nbg1',
        serverType: 'cx22',
        image: 'ubuntu-24.04',
        publicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB-example uploaded-key',
        publicIpPollAttempts: 1,
        publicIpPollIntervalSeconds: 1,
    ));

    expect($result->serverId)->toBe(999)
        ->and($result->name)->toBe('sword-web')
        ->and($result->location)->toBe('nbg1')
        ->and($result->serverType)->toBe('cx22')
        ->and($result->status)->toBe('initializing')
        ->and($result->publicIp)->toBe('203.0.113.10')
        ->and($result->sshKeyId)->toBe(44)
        ->and($result->sshKeyName)->toBe('uploaded-key')
        ->and($result->sshKeyStatus)->toBe('uploaded');

    expect($client->requests)->toHaveCount(3)
        ->and($client->requests[0]->getMethod())->toBe('POST')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.hetzner.cloud/v1/ssh_keys')
        ->and($client->requests[1]->getMethod())->toBe('POST')
        ->and((string) $client->requests[1]->getUri())->toBe('https://api.hetzner.cloud/v1/servers')
        ->and($client->requests[2]->getMethod())->toBe('GET')
        ->and((string) $client->requests[2]->getUri())->toBe('https://api.hetzner.cloud/v1/servers/999');

    expect(json_decode((string) $client->requests[1]->getBody(), true))
        ->toBe([
            'name' => 'sword-web',
            'location' => 'nbg1',
            'server_type' => 'cx22',
            'image' => 'ubuntu-24.04',
            'ssh_keys' => [44],
            'public_net' => [
                'ipv4_enabled' => true,
                'ipv6_enabled' => true,
            ],
        ]);
});

test('HetznerServerCreator resolves an existing key by name', function () {
    $client = new class([new Response(200, [], json_encode(['ssh_keys' => [['id' => 22, 'name' => 'existing-key', 'public_key' => 'ssh-ed25519 AAAA existing-key']]])), new Response(201, [], json_encode(['server' => ['id' => 123, 'name' => 'sword-app', 'status' => 'running', 'server_type' => ['name' => 'cx11'], 'datacenter' => ['location' => ['name' => 'hel1']]]])), new Response(200, [], json_encode(['server' => ['public_net' => ['ipv4' => ['ip' => '198.51.100.7']]]]))]) implements ClientInterface
    {
        /** @var array<int, RequestInterface> */
        public array $requests = [];

        /**
         * @param  array<int, ResponseInterface>  $responses
         */
        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return array_shift($this->responses);
        }
    };

    $creator = new HetznerServerCreator($client, new HttpFactory);

    $result = $creator->create(new CreateHetznerServerData(
        apiKey: 'test-token',
        name: 'sword-app',
        location: 'hel1',
        serverType: 'cx11',
        image: 'ubuntu-24.04',
        publicKey: 'existing-key',
        publicIpPollAttempts: 1,
        publicIpPollIntervalSeconds: 1,
    ));

    expect($result->serverId)->toBe(123)
        ->and($result->publicIp)->toBe('198.51.100.7')
        ->and($result->sshKeyId)->toBe(22)
        ->and($result->sshKeyName)->toBe('existing-key')
        ->and($result->sshKeyStatus)->toBe('existing');

    expect($client->requests)->toHaveCount(3)
        ->and($client->requests[0]->getMethod())->toBe('GET')
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.hetzner.cloud/v1/ssh_keys');

    expect(json_decode((string) $client->requests[1]->getBody(), true))
        ->toMatchArray([
            'name' => 'sword-app',
            'location' => 'hel1',
            'server_type' => 'cx11',
            'image' => 'ubuntu-24.04',
            'ssh_keys' => [22],
        ]);
});
