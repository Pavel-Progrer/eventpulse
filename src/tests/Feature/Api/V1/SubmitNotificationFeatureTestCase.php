<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Common scaffolding for the two `POST /api/v1/notifications` feature suites
 * (`SubmitNotificationDispatchTest`, `SubmitNotificationIdempotencyTest`).
 *
 * Both suites need the same fixtures:
 *   - `Bus::fake()` so dispatched jobs are observable without being executed.
 *   - One writer API key (the standard caller).
 *   - A second writer API key (used by idempotency-scoping cases).
 *   - A valid request body factory.
 *   - A header builder.
 *
 * Splitting out the base avoids the alternative — copying ~50 lines of
 * setUp / helpers between two files — while keeping each test file focused
 * on a single behaviour theme. The base class is `abstract` because it has
 * no tests of its own and should never be instantiated as a runnable suite.
 */
abstract class SubmitNotificationFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected ApiKey $writerKey;
    protected ApiKey $otherWriterKey;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $this->writerKey = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_004a',
            'scopes'     => ['notifications:write', 'notifications:read'],
            'status'     => 'active',
            'label'      => 'day-4 test writer A',
        ]);

        $this->otherWriterKey = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_004b',
            'scopes'     => ['notifications:write'],
            'status'     => 'active',
            'label'      => 'day-4 test writer B',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validEmailBody(): array
    {
        return [
            'channel'   => 'email',
            'recipient' => 'user@example.com',
            'payload'   => [
                'subject'   => 'Day-4 integration test',
                'body_text' => 'Body text for the integration test.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function headersFor(
        ApiKey $key,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): array {
        $headers = [
            'Authorization'   => "Bearer {$key->identifier}",
            'Idempotency-Key' => $idempotencyKey,
        ];

        if ($correlationId !== null) {
            $headers['X-Correlation-ID'] = $correlationId;
        }

        return $headers;
    }
}