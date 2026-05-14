<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the request body and required headers for `POST /api/v1/notifications`.
 *
 * Scope of validation here (per ADR-0003 §3):
 *  - Shape and primitive types of the request body and required headers.
 *  - Channel/payload field-presence rules that depend on the `channel` value.
 *
 * Out of scope here (handled by the domain layer):
 *  - Recipient format precision (RFC 5321 email, E.164 SMS, UUID for webhook
 *    destinations). The FormRequest checks "string non-empty"; the domain's
 *    value-object factories enforce the precise format.
 *  - SMS body length (1600 chars), email body presence variants, webhook
 *    payload non-emptiness — these are `NotificationPayload`'s job.
 *
 * The duplication-avoidance principle (ADR-0003 §3) is: the FormRequest
 * filters out shapes the domain would reject anyway, but never re-implements
 * a domain invariant. When the FormRequest passes, the domain may still
 * throw — and that exception is mapped to a 422 by the global exception
 * handler.
 */
final class SubmitNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication and scope-checking happen in middleware
        // (`auth.api-key`, `scope:notifications:write`) before this
        // FormRequest runs. By this point the request is already
        // authenticated and authorised; nothing else to do here.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        // Header rules. The `Idempotency-Key` is required per the OpenAPI
        // spec; correlation id is optional.
        $headerRules = [
            'Idempotency-Key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[\x21-\x7E]+$/'],
            'X-Correlation-ID' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ];

        $bodyRules = [
            'channel'    => ['required', 'string', Rule::in(['email', 'webhook', 'sms'])],
            'recipient'  => ['required', 'string', 'min:1', 'max:320'],
            'priority'   => ['nullable', 'string', Rule::in(['low', 'normal', 'high'])],
            'payload'    => ['required', 'array'],
        ];

        $channel = $this->input('channel');

        // Per-channel payload field rules. The FormRequest catches the
        // shape mistakes ("you sent SMS without a body") so the caller
        // sees a clear 422 with field-level details, rather than a
        // generic "InvalidArgumentException" from the domain.
        $payloadRules = match ($channel) {
            'email' => [
                'payload.subject'   => ['required', 'string', 'min:1', 'max:998'],
                'payload.body_text' => ['nullable', 'string', 'max:524288'],
                'payload.body_html' => ['nullable', 'string', 'max:524288'],
                'payload.reply_to'  => ['nullable', 'email:rfc'],
            ],
            'webhook' => [
                'payload.body'    => ['required', 'array'],
                'payload.headers' => ['nullable', 'array'],
            ],
            'sms' => [
                'payload.body' => ['required', 'string', 'min:1', 'max:1600'],
            ],
            default => [],
        };

        // Headers are validated through the merged data set; Laravel
        // attaches them via prepareForValidation below.
        return $headerRules + $bodyRules + $payloadRules;
    }

    /**
     * Pre-validation hook: lift the required headers into the input bag so
     * the validator can apply rules to them with the same syntax as body
     * fields. Without this, header-validation rules would silently be
     * ignored.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'Idempotency-Key'  => $this->header('Idempotency-Key'),
            'X-Correlation-ID' => $this->header('X-Correlation-ID'),
        ]);
    }

    /**
     * Cross-field rules that the standard `rules()` syntax cannot express.
     * Specifically: at least one of `body_text` / `body_html` is required
     * for email payloads, mirroring `NotificationPayload::validateEmail()`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('channel') !== 'email') {
                return;
            }

            $hasText = is_string($this->input('payload.body_text'))
                && $this->input('payload.body_text') !== '';
            $hasHtml = is_string($this->input('payload.body_html'))
                && $this->input('payload.body_html') !== '';

            if (!$hasText && !$hasHtml) {
                $v->errors()->add(
                    'payload',
                    'Email payload must include at least one of "body_text" or "body_html".',
                );
            }
        });
    }

    /**
     * Friendlier error keys. Headers especially benefit from being shown
     * with their HTTP-cased names rather than the merged-input names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'Idempotency-Key'  => 'Idempotency-Key header',
            'X-Correlation-ID' => 'X-Correlation-ID header',
            'payload.body_text' => 'payload.body_text',
            'payload.body_html' => 'payload.body_html',
        ];
    }
}
