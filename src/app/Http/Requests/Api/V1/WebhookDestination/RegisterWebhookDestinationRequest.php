<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\WebhookDestination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body for `POST /api/v1/webhook-destinations`.
 *
 * Validation rules mirror the OpenAPI `CreateWebhookDestinationRequest` schema:
 *  - `url`    — required, HTTPS, max 2048 characters.
 *  - `secret` — required, 16–256 characters.
 *  - `name`   — optional, max 128 characters.
 *
 * The HTTPS-only rule is validated here AND enforced by the domain aggregate
 * (`WebhookDestination::register()`). The HTTP layer catches it first for a
 * better error message; the domain is self-defending regardless of the caller.
 *
 * Why the secret is validated here and not only in the domain:
 *  The domain aggregate does not store the secret at all (domain.md §5.2.3).
 *  There is no aggregate method that receives and validates the secret's
 *  character count. The FormRequest is the appropriate place for this
 *  structural validation.
 */
final class RegisterWebhookDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth.api-key + scope middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url'    => ['required', 'string', 'url', 'max:2048', 'regex:/^https:\/\//'],
            'secret' => ['required', 'string', 'min:16', 'max:256'],
            'name'   => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.regex' => 'The URL must use the https:// scheme. HTTP endpoints are not accepted.',
        ];
    }
}
