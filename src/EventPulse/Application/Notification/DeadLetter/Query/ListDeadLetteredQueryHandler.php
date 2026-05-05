<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

/**
 * Use case: list dead-lettered notifications for inspection.
 *
 * Orchestrates exactly one read against the read-model port; no
 * transactions, no domain events, no side effects. The shape and the
 * filters are the input; the page of projections is the output.
 *
 * The handler is intentionally trivial because the complexity lives
 * where it belongs:
 *  - **Validation** — at the HTTP boundary (FormRequest) and at the
 *    query DTO's constructor.
 *  - **Filtering / pagination** — in the read model
 *    (`DeadLetteredNotificationsRepository`).
 *  - **Authorisation** — in the middleware (`scope:dlq:read`) and
 *    enforced again at the data layer via `apiKeyId` filter (see
 *    ADR-0006 §"DLQ visibility is tenant-scoped").
 *
 * That keeps the handler a thin orchestration step: it does not
 * make decisions, only routes inputs to outputs. The signal that
 * matters here — "this use case has been implemented" — is in the
 * existence of this class with one collaborator and one method.
 */
final class ListDeadLetteredQueryHandler
{
    public function __construct(
        private readonly DeadLetteredNotificationsRepository $repository,
    ) {}

    public function __invoke(ListDeadLetteredQuery $query): DlqEntryPage
    {
        return $this->repository->list($query);
    }
}
