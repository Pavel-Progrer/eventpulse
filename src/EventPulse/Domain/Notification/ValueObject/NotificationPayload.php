<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\ValueObject;

use EventPulse\Domain\Notification\Enum\Channel;

/**
 * The content to be delivered, validated against the channel it will travel on
 * (invariant 5.1.10).
 *
 * Each channel imposes its own shape requirements. Rather than one giant
 * validation blob, each channel's rules are isolated in a private method.
 * The payload itself is stored as a plain array (JSON-serialisable); the
 * schema lives in the code, not in a separate descriptor.
 *
 * Phase 3 note: `CanonicalPayload` (domain.md §7) is a distinct value object
 * that wraps multi-channel source content; that is not modelled here. A
 * single-channel notification's payload is always finalised content.
 */
final class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly array $data,
        private readonly Channel $channel,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function forChannel(array $data, Channel $channel): self
    {
        self::validate($data, $channel);

        return new self($data, $channel);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    /**
     * Two payloads are equal when they belong to the same channel and carry
     * the same content, irrespective of the order in which keys were declared.
     *
     * Why not a simple `$this->data === $other->data`:
     *   PHP's strict array equality is order-sensitive for associative arrays.
     *   Persistence round-trips through PostgreSQL `jsonb` (or any JSON
     *   transport) do not preserve key order — `jsonb` reorders by hash, and
     *   `json_decode` rebuilds the array in JSON's textual order. A request
     *   that submits `{"subject": "x", "text": "y"}` and a replay of the same
     *   request would compare unequal under `===` after a save/load cycle,
     *   breaking idempotent replay.
     *
     *   We define "same content" semantically: associative arrays are equal
     *   when they have the same set of keys with equal values; lists (zero-
     *   indexed sequential arrays) are equal when they have the same elements
     *   in the same order, because for lists order *is* meaningful (e.g.,
     *   ordered headers in a webhook).
     */
    public function equals(self $other): bool
    {
        return $this->channel === $other->channel
            && self::deepEquals($this->data, $other->data);
    }

    /**
     * Recursive deep equality for JSON-shaped data.
     *
     * Recursion handles nested webhook payloads, where any value may itself
     * be an array. The leaf comparison is `===`, so scalars must match by
     * type — `1` and `"1"` are not equal, which matches the strictness the
     * domain expects elsewhere.
     */
    private static function deepEquals(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }

            // Lists (zero-indexed sequential): compare element-wise in order.
            if (array_is_list($a) || array_is_list($b)) {
                if (! array_is_list($a) || ! array_is_list($b)) {
                    return false;
                }

                foreach ($a as $i => $value) {
                    if (! self::deepEquals($value, $b[$i])) {
                        return false;
                    }
                }

                return true;
            }

            // Associative: compare by key set, ignoring declaration order.
            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! self::deepEquals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    // ---------------------------------------------------------------------------
    // Channel-specific validation
    // ---------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private static function validate(array $data, Channel $channel): void
    {
        match ($channel) {
            Channel::Email => self::validateEmail($data),
            Channel::Webhook => self::validateWebhook($data),
            Channel::Sms => self::validateSms($data),
        };
    }

    /**
     * Email payload requires a non-empty subject and body (text or html).
     * Both may be present simultaneously.
     *
     * @param  array<string, mixed>  $data
     */
    private static function validateEmail(array $data): void
    {
        if (empty($data['subject']) || ! is_string($data['subject'])) {
            throw new \InvalidArgumentException('Email payload must include a non-empty string "subject".');
        }

        $hasText = isset($data['text']) && is_string($data['text']) && $data['text'] !== '';
        $hasHtml = isset($data['html']) && is_string($data['html']) && $data['html'] !== '';

        if (! $hasText && ! $hasHtml) {
            throw new \InvalidArgumentException(
                'Email payload must include at least one of "text" or "html" body.'
            );
        }
    }

    /**
     * Webhook payload accepts any JSON-serialisable structure; the only
     * constraint is that it is a non-empty array (an empty object delivers
     * nothing meaningful, and the caller who does this almost certainly
     * made a mistake).
     *
     * @param  array<string, mixed>  $data
     */
    private static function validateWebhook(array $data): void
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Webhook payload must not be empty.');
        }
    }

    /**
     * SMS payload is a single string "body". Max 1600 characters covers up to
     * 10 concatenated SMS segments; longer values are rejected at the domain
     * layer because they cannot be delivered and would incur cost silently.
     *
     * @param  array<string, mixed>  $data
     */
    private static function validateSms(array $data): void
    {
        if (empty($data['body']) || ! is_string($data['body'])) {
            throw new \InvalidArgumentException('SMS payload must include a non-empty string "body".');
        }

        if (mb_strlen($data['body']) > 1600) {
            throw new \InvalidArgumentException(
                sprintf(
                    'SMS body must not exceed 1600 characters; got %d.',
                    mb_strlen($data['body'])
                )
            );
        }
    }
}
