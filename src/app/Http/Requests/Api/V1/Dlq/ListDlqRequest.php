<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Dlq;

use EventPulse\Domain\Notification\Enum\Channel;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query params for `GET /api/v1/dlq`.
 *
 * Mirrors the OpenAPI spec's parameter list:
 *  - `reason` — restricted to known DLQ reasons.
 *  - `channel` — restricted to enum values.
 *  - `created_after`, `created_before` — ISO 8601 timestamps.
 *  - `limit` — default 25, max 100. Default chosen to fit on a single
 *    operator screen without scrolling; max enforced at the application
 *    layer too (`ListDeadLetteredQuery`'s constructor) as defence in
 *    depth.
 *  - `cursor` — opaque string from a prior page's `next_cursor`.
 *
 * The handler runs after this; invalid input never reaches it.
 */
final class ListDlqRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Spec lists three reasons, but we only emit two today
            // (max_retries_exceeded, unrecoverable_error). `manual` is a
            // future "operator dead-lettered this on purpose" feature
            // that ADR-0001's exclusions defer; if a caller filters by it
            // we accept the value and return zero results — clearer than
            // a 422 for a future-valid value.
            'reason' => ['sometimes', 'string', 'in:max_retries_exceeded,unrecoverable_error,manual'],

            'channel' => ['sometimes', 'string', 'in:' . implode(',', array_map(
                static fn(Channel $c): string => $c->value,
                Channel::cases(),
            ))],

            'created_after'  => ['sometimes', 'date'],
            'created_before' => ['sometimes', 'date'],

            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'min:1', 'max:255'],
        ];
    }
}
