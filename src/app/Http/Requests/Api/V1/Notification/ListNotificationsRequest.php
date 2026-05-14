<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters for `GET /api/v1/notifications`.
 *
 * All parameters are optional. The FormRequest normalises array-style query
 * params (`?status[]=queued&status[]=processing`) and single-value forms
 * (`?status=queued`) to a consistent `list<string>` before the controller
 * maps them to domain enums.
 */
final class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by `auth.api-key` + `scope` middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status'         => ['sometimes', 'array'],
            'status.*'       => ['string', 'in:queued,processing,dispatched,failed,dead_lettered,retry_scheduled'],
            'channel'        => ['sometimes', 'array'],
            'channel.*'      => ['string', 'in:email,sms,webhook'],
            'correlation_id' => ['sometimes', 'string', 'max:255'],
            'created_after'  => ['sometimes', 'date'],
            'created_before' => ['sometimes', 'date'],
            'limit'          => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor'         => ['sometimes', 'string'],
        ];
    }
}
