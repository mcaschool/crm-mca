<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorization code de un solo uso (PKCE S256). Solo SHA-256.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $client_id
 * @property int $mcp_client_id
 * @property int $user_id
 * @property string $redirect_uri
 * @property string $code_challenge
 * @property string $scope
 * @property string $resource
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 */
class McpOauthAuthCode extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_oauth_auth_codes';

    protected $fillable = [
        'code_hash', 'client_id', 'mcp_client_id', 'user_id', 'redirect_uri',
        'code_challenge', 'scope', 'resource', 'expires_at', 'used_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
