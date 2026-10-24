<?php

declare(strict_types=1);

namespace Specrig\Laravel;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;

// A connection that keeps what is pushed until the harness's worker fires it,
// through the sync queue's own events and failure handling.
final class Queue extends SyncQueue
{
    public function __construct(private readonly Queued $queued, private readonly string $default, bool $dispatchAfterCommit = false)
    {
        parent::__construct($dispatchAfterCommit);
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $this->named($queue), $data), $queue, null,
            fn (string $payload, ?string $queue) => $this->pushRaw($payload, $queue));
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->queued->keep($this->getConnectionName(), $this->named($queue), $payload, $this->availableAt($options['delay'] ?? 0));
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $this->named($queue), $data), $queue, $delay,
            fn (string $payload, ?string $queue, $delay) => $this->pushRaw($payload, $queue, ['delay' => $delay]));
    }

    public function size($queue = null)
    {
        return $this->queued->count($this->getConnectionName(), $queue, null, Carbon::now()->getTimestamp());
    }

    public function pendingSize($queue = null)
    {
        return $this->queued->count($this->getConnectionName(), $queue, true, Carbon::now()->getTimestamp());
    }

    public function delayedSize($queue = null)
    {
        return $this->queued->count($this->getConnectionName(), $queue, false, Carbon::now()->getTimestamp());
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return $this->queued->oldest($this->getConnectionName(), $queue);
    }

    public function payload(object $job): string
    {
        return $this->createPayload($job, $this->default);
    }

    public function job(string $payload, string $queue): SyncJob
    {
        return $this->resolveJob($payload, $queue);
    }

    public function fire(Job $job): void
    {
        try {
            $this->raiseBeforeJobEvent($job);
            $job->fire();
            $this->raiseAfterJobEvent($job);
        } catch (\Throwable $e) {
            $exceptionOccurred = $e;
            $this->handleException($job, $e);
        } finally {
            $this->raiseJobAttemptedEvent($job, $exceptionOccurred ?? null);
        }
    }

    private function named(?string $queue): string
    {
        return $queue ?? $this->default;
    }
}
