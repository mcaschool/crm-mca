<?php

declare(strict_types=1);

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cliente OAuth público registrado por DCR. NO tiene secreto ni otorga acceso: solo es
 * una identidad + redirect_uris validadas para el flujo Authorization Code + PKCE.
 *
 * @property int $id
 * @property string $client_id
 * @property string|null $client_name
 * @property array<int,string> $redirect_uris
 * @property array<int,string> $grant_types
 * @property string $token_endpoint_auth_method
 * @property string|null $scope
 * @property int|null $mcp_client_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class McpOauthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $fillable = [
        'client_id', 'client_name', 'redirect_uris', 'grant_types',
        'token_endpoint_auth_method', 'scope', 'mcp_client_id',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'grant_types' => 'array',
        ];
    }
}
