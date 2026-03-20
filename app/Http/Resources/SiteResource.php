<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'site_label' => $this->site_label,
            'domain' => $this->domain,
            'php_version' => $this->php_version,
            'db_name' => $this->db_name,
            'db_user' => $this->db_user,
            'status' => $this->status,
            'current_step' => $this->current_step,
            'install_log' => $this->install_log ?? [],
            'installed_at' => $this->installed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'server' => [
                'id' => $this->server->id,
                'name' => $this->server->name,
                'ip_address' => $this->server->ip_address,
            ],
        ];
    }
}
