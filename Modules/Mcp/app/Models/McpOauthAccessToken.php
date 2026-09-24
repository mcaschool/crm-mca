<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access token opaco (Bearer). Solo SHA-256.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $client_id
 * @property int $mcp_client_id
 * @property string $scope
 * @property string $resource
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class McpOauthAccessToken extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_oauth_access_tokens';

    protected $fillable = [
        'token_hash', 'client_id', 'mcp_client_id', 'scope', 'resource',
        'expires_at', 'revoked_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return array<int,string> */
    public function scopes(): array
    {
        return array_values(array_filter(explode(' ', $this->scope)));
    }

    /**
     * @return BelongsTo<McpClient, $this>
     */
    public function mcpClient(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id');
    }
}
