<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\WebhookDestination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters for `GET /api/v1/webhook-destinations`.
 *
 * Mirrors the OpenAPI cursor-pagination parameters shared across list
 * endpoints: `limit` (1–100, default 20) and `cursor` (opaque string).
 */
final class ListWebhookDestinationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:128'],
        ];
    }
}
