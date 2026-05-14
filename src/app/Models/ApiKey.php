<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence representation of the ApiKey aggregate.
 *
 * Day 3: basic authentication (identifier, scopes, status).
 * Day 10: `rate_limit_per_minute` added for per-key write throttle overrides.
 *
 * Per ADR-0002, the domain `ApiKey` aggregate is a separate object — *not*
 * this Eloquent model. This class lives in `app/Models` because it is a
 * Laravel construct; the domain aggregate (when implemented later) lives
 * under `src/EventPulse/Domain/ApiKey/`.
 *
 * @property string                           $id
 * @property string                           $identifier
 * @property string|null                      $secret_hash
 * @property array<int, string>               $scopes
 * @property string                           $status
 * @property string|null                      $label
 * @property int|null                         $rate_limit_per_minute
 * @property \Illuminate\Support\Carbon|null  $revoked_at
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
        'rate_limit_per_minute',
        'revoked_at',
    ];

    protected $casts = [
        'scopes'                 => 'array',
        'rate_limit_per_minute'  => 'integer',
        'revoked_at'             => 'datetime',
    ];

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
