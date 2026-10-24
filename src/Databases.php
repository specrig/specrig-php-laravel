<?php

declare(strict_types=1);

namespace Specrig\Laravel;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Specrig\Databases as Seam;
use Symfony\Component\Console\Output\BufferedOutput;

final class Databases implements Seam
{
    public function __construct(private readonly Application $app)
    {
    }

    public function migrate(): void
    {
        $output = new BufferedOutput();
        if ($this->app->make(Kernel::class)->call('migrate', ['--force' => true], $output) !== 0) {
            throw new \RuntimeException(trim($output->fetch()));
        }
    }

    public function bookkeeping(): array
    {
        $migrations = $this->app['config']->get('database.migrations');
        return [is_array($migrations) ? $migrations['table'] ?? 'migrations' : ($migrations ?? 'migrations')];
    }

    public function refuse(string $address, string $refusing): void
    {
        foreach ($this->connections($address) as $name) {
            $this->app['config']->set("database.connections.{$name}.url", $refusing);
            $this->app['db']->purge($name);
        }
    }

    public function fail(string $address, \Throwable $error): void
    {
        foreach ($this->connections($address) as $name) {
            $this->app['db']->connection($name)->beforeExecuting(fn () => throw $error);
        }
    }

    /** @return list<string> */
    private function connections(string $address): array
    {
        $named = [];
        foreach ($this->app['config']->get('database.connections', []) as $name => $connection) {
            if (($connection['url'] ?? null) === $address) {
                $named[] = (string) $name;
            }
        }
        return $named;
    }
}
