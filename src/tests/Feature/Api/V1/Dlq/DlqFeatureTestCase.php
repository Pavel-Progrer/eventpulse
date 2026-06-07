<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Dlq;

use App\Models\ApiKey;
use Tests\TestCase;

abstract class DlqFeatureTestCase extends TestCase
{
    protected ApiKey $reader;        // dlq:read

    protected ApiKey $otherTenant;   // dlq:read but different api key

    protected ApiKey $writeOnly;     // notifications:write only — must 403

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_001',
            'scopes' => ['dlq:read'],
            'status' => 'active',
            'label' => 'reader A',
        ]);

        $this->otherTenant = ApiKey::query()->create([
            'identifier' => 'ep_live_dlq_reader_002',
            'scopes' => ['dlq:read'],
            'status' => 'active',
            'label' => 'reader B',
        ]);

        $this->writeOnly = ApiKey::query()->create([
            'identifier' => 'ep_live_writer_only_001',
            'scopes' => ['notifications:write'],
            'status' => 'active',
            'label' => 'writer with no DLQ access',
        ]);
    }
}
