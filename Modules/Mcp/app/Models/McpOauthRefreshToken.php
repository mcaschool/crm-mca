<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Refresh token opaco y ROTATORIO. Solo SHA-256.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $client_id
 * @property int $mcp_client_id
 * @property string $scope
 * @property string $resource
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property int|null $rotated_to_id
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class McpOauthRefreshToken extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_oauth_refresh_tokens';

    protected $fillable = [
        'token_hash', 'client_id', 'mcp_client_id', 'scope', 'resource',
        'expires_at', 'used_at', 'rotated_to_id', 'revoked_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->used_at === null && $this->expires_at->isFuture();
    }
}
