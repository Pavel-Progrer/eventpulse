<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence representation of the ApiKey aggregate.
 *
 * Day 3 use only: this model is consumed by the `AuthenticateApiKey` middleware
 * to resolve an Authorization header into an api_key_id and scope set. Day 9
 * (HMAC verification) will read `secret_hash` from the same row.
 *
 * Per ADR-0002, the domain `ApiKey` aggregate is a separate object — *not*
 * this Eloquent model. This class lives in `app/Models` because it is a
 * Laravel construct; the domain aggregate (when implemented later) lives
 * under `src/EventPulse/Domain/ApiKey/`.
 *
 * @property string $id
 * @property string $identifier
 * @property string|null $secret_hash
 * @property array<int, string> $scopes
 * @property string $status
 * @property string|null $label
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class ApiKey extends Model
{
    use HasUuids;

    protected $table = 'api_keys';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'identifier',
        'secret_hash',
        'scopes',
        'status',
        'label',
        'revoked_at',
    ];

    protected $casts = [
        'scopes'     => 'array',
        'revoked_at' => 'datetime',
    ];

    public function getId(): string
    {
        return (string) $this->apiKeyId();
    }

    /**
     * Whether the key carries the given scope. Matches the `admin` umbrella —
     * any key with `admin` is treated as having every scope.
     */
    public function hasScope(string $scope): bool
    {
        if (in_array('admin', $this->scopes, true)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }

    /**
     * Status accessor mirrors the domain language even though the column
     * stores a plain string.
     */
    protected function isRevoked(): Attribute
    {
        return Attribute::get(fn(): bool => $this->status === 'revoked');
    }
}
