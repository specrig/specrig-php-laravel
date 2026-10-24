<?php

declare(strict_types=1);

namespace Specrig\Laravel;

final class Queued
{
    /** @var list<array{connection: string, queue: string, payload: string, at: int}> */
    private array $jobs = [];

    public function keep(string $connection, string $queue, string $payload, int $at): string
    {
        $this->jobs[] = ['connection' => $connection, 'queue' => $queue, 'payload' => $payload, 'at' => $at];
        return (string) (json_decode($payload, true)['uuid'] ?? count($this->jobs));
    }

    /** @return array{connection: string, queue: string, payload: string, at: int}|null */
    public function next(int $now): ?array
    {
        foreach ($this->jobs as $i => $job) {
            if ($job['at'] <= $now) {
                array_splice($this->jobs, $i, 1);
                return $job;
            }
        }
        return null;
    }

    public function count(string $connection, ?string $queue, ?bool $due, int $now): int
    {
        return count(array_filter($this->jobs, fn (array $job) => $job['connection'] === $connection
            && ($queue === null || $job['queue'] === $queue)
            && ($due === null || ($job['at'] <= $now) === $due)));
    }

    public function oldest(string $connection, ?string $queue): ?int
    {
        foreach ($this->jobs as $job) {
            if ($job['connection'] === $connection && ($queue === null || $job['queue'] === $queue)) {
                return json_decode($job['payload'], true)['createdAt'] ?? null;
            }
        }
        return null;
    }
}
