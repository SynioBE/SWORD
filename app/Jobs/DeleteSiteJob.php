<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SSH2;

class DeleteSiteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Site $site) {}

    public function handle(): void
    {
        $site = $this->site;
        $server = $site->server;

        $deleteScriptUrl = route('sites.scripts.delete', [
            'site' => $site->id,
            'token' => $site->install_token,
        ]);

        $privateKey = EC::loadFormat('OpenSSH', $server->ssh_private_key);

        $ssh = new SSH2($server->ip_address, $server->ssh_port);
        $ssh->login('root', $privateKey);

        $ssh->setTimeout(0);
        $ssh->exec(sprintf('wget -qO delete-wp-site.sh "%s" && bash delete-wp-site.sh > delete-wp-site.log 2>&1', $deleteScriptUrl));

        $site->delete();
    }
}
