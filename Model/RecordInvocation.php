<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\InvocationListenerInterface;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Psr\Log\LoggerInterface;

/**
 * Records every MCP ability call as an activity of the "mcp" channel, with the call's detail beside it
 *
 * - The call opens an operation when it starts and closes it when it ends, so what its ability changes shares the
 *   call's correlation id, and is attributed to the caller via the client (CallsInProgress).
 * - The activity's action and subject are the ability the client asked for; its outcome is how the call ended: a
 *   refusal by the exposure policy or the caller's ACL is "denied", and so is a call waiting for its confirmation.
 * - The detail keeps the client, the transport, the ability's source, access and ACL, why a call was refused, and the
 *   arguments and the result, masked and cut to a size (CallPayload). A call waiting for confirmation keeps no
 *   message: it carries the one-time confirmation token.
 * - Recording never changes how the call ends: a failure is logged.
 */
class RecordInvocation implements InvocationListenerInterface
{
    private const MESSAGE_MAX_LENGTH = 500;
    private const NEEDS_CONFIRMATION = 'needs_confirmation';
    private const OUTCOMES = [
        'succeeded' => Outcome::Success,
        'failed' => Outcome::Failure,
        'denied' => Outcome::Denied,
        'needs_confirmation' => Outcome::Denied,
    ];

    /**
     * @param CallsInProgress $calls
     * @param ActivityBuilderInterface $activityBuilder
     * @param ActivityRecorderInterface $activityRecorder
     * @param SubjectInterfaceFactory $subjectFactory
     * @param MillisecondClockInterface $clock
     * @param CallPayload $payload
     * @param CallStore $store
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CallsInProgress $calls,
        private readonly ActivityBuilderInterface $activityBuilder,
        private readonly ActivityRecorderInterface $activityRecorder,
        private readonly SubjectInterfaceFactory $subjectFactory,
        private readonly MillisecondClockInterface $clock,
        private readonly CallPayload $payload,
        private readonly CallStore $store,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function started(InvocationInterface $invocation): void
    {
        $this->calls->begin($invocation);
    }

    /**
     * @inheritDoc
     */
    public function finished(InvocationInterface $invocation, InvocationResultInterface $result): void
    {
        try {
            $this->record($invocation, $result);
        } catch (\Throwable $exception) {
            $this->logger->error('An MCP call could not be recorded.', ['exception' => $exception]);
        } finally {
            $this->calls->end();
        }
    }

    /**
     * Record the call's activity, then its detail
     *
     * @param InvocationInterface $invocation
     * @param InvocationResultInterface $result
     * @return void
     */
    private function record(InvocationInterface $invocation, InvocationResultInterface $result): void
    {
        $outcome = $result->getOutcome();
        $name = mb_substr($invocation->getAbilityName(), 0, 255);
        $durationMs = $result->getDurationMs();
        $startedAtMs = $this->clock->nowMs() - (int) round($durationMs);
        $message = $this->messageOf($result);

        $activityId = $this->activityRecorder->record($this->activityBuilder->build(
            McpChannel::CODE,
            $name === '' ? '(no ability)' : $name,
            self::OUTCOMES[$outcome->value],
            $this->subjectFactory->create(['type' => McpChannel::SUBJECT_TYPE, 'label' => $name === '' ? '-' : $name]),
            $durationMs,
            $message,
            $startedAtMs
        ));
        if ($activityId === null) {
            return;
        }

        $ability = $result->getAbility();
        $arguments = $this->payload->of($invocation->getArguments());
        $value = $outcome === InvocationOutcome::Succeeded ? $this->payload->of($result->getValue()) : null;
        $client = $invocation->getClient();
        $this->store->save($activityId, [
            'occurredAtMs' => $startedAtMs,
            'ability' => $name,
            'clientName' => $client->getName(),
            'clientVersion' => $client->getVersion(),
            'transport' => $invocation->getTransport(),
            'source' => $ability?->getSource(),
            'sourceReference' => $ability?->getSourceReference(),
            'access' => $ability?->getAccess()->value,
            'aclResources' => $ability === null ? [] : $ability->getAclResources(),
            'outcome' => $outcome->value,
            'denialReason' => $outcome === InvocationOutcome::NeedsConfirmation
                ? self::NEEDS_CONFIRMATION
                : $result->getDenialReason(),
            'message' => $message,
            'arguments' => $arguments['text'],
            'result' => $value['text'] ?? null,
            'truncated' => $arguments['truncated'] || ($value['truncated'] ?? false),
        ]);
    }

    /**
     * The message the activity shows with its outcome; none for a call waiting for its confirmation
     *
     * @param InvocationResultInterface $result
     * @return string|null
     */
    private function messageOf(InvocationResultInterface $result): ?string
    {
        if ($result->getOutcome() === InvocationOutcome::NeedsConfirmation) {
            return null;
        }

        $message = trim($result->getMessage());

        return $message === '' ? null : mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);
    }
}
