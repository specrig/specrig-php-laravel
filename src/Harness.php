<?php

declare(strict_types=1);

namespace Specrig\Laravel;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PhpAmqpLib\Connection\AbstractConnection;
use Specrig\Amqp\Bus;
use Specrig\Databases as DatabaseSeam;
use Specrig\Handlers;
use Specrig\Http\Client;
use Specrig\Http\Inbound;
use Specrig\Http\Request;
use Specrig\Http\Response;
use Specrig\Http\Unreachable;
use Specrig\Logged;
use Specrig\Rig;
use Specrig\StandIns;
use Specrig\Worked;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class Harness extends Rig
{
    private const WORK = 1000;

    private ?Application $app = null;

    private ?Queued $queued = null;

    /** @var list<Logged> */
    private array $logs = [];

    private string $output = '';

    public function app(): Application
    {
        $this->boot();
        return $this->app ?? throw new \LogicException("{$this->service()} did not boot");
    }

    public function get(string $id): mixed
    {
        return $this->app()->make($id);
    }

    public function http(): Client
    {
        return new Client(fn (Request $request) => Inbound::exchange($this->trace, $request, fn (Request $r) => $this->handle($r)));
    }

    public function run(string|object $job): void
    {
        $this->traced('command', (new \ReflectionClass($job))->getShortName(), null, function () use ($job) {
            if ($job instanceof ShouldQueue) {
                $queue = new Queue(new Queued(), 'default');
                $queue->setContainer($this->app());
                $queue->setConnectionName('specrig');
                $queue->fire($queue->job($queue->payload($job), 'default'));
                return;
            }
            $instance = is_string($job) ? $this->app()->make($job) : $job;
            foreach (['handle', '__invoke', 'run', 'execute'] as $method) {
                if (method_exists($instance, $method)) {
                    $this->app()->call([$instance, $method]);
                    return;
                }
            }
            throw new \LogicException($instance::class . ' has no handle, __invoke, run or execute to run it by');
        });
    }

    public function command(string $name, string ...$args): int
    {
        return $this->traced('command', $name, $args === [] ? null : $args, function () use ($name, $args) {
            $kernel = $this->app()->make(ConsoleKernel::class);
            $input = new ArgvInput(['artisan', $name, ...$args]);
            $output = new BufferedOutput();
            $code = $kernel->handle($input, $output);
            $kernel->terminate($input, $code);
            $this->output = $output->fetch();
            return $code;
        });
    }

    public function output(): string
    {
        return $this->output;
    }

    public function work(): array
    {
        $this->boot();
        $worked = [];
        for ($i = 0; $i < self::WORK && ($next = $this->queued?->next(Carbon::now()->getTimestamp())) !== null; $i++) {
            $queue = $this->app()['queue']->connection($next['connection']);
            $job = $queue->job($next['payload'], $next['queue']);
            $name = (new \ReflectionClass($job->resolveName()))->getShortName();
            try {
                $this->traced('command', $name, null, fn () => $queue->fire($job));
                $worked[] = new Worked($name);
            } catch (\Throwable $e) {
                $worked[] = new Worked($name, $e);
            }
        }
        return $worked;
    }

    /** @return list<Logged> */
    public function logs(): array
    {
        return $this->logs;
    }

    public function databases(): DatabaseSeam
    {
        return new Databases($this->app());
    }

    protected function framework(): array
    {
        $caches = sys_get_temp_dir() . '/specrig-laravel-' . getmypid();
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_CONFIG_CACHE' => "{$caches}/config.php",
            'APP_ROUTES_CACHE' => "{$caches}/routes.php",
            'APP_EVENTS_CACHE' => "{$caches}/events.php",
        ];
    }

    protected function start(): void
    {
        $app = require static::root() . '/bootstrap/app.php';
        if (!$app instanceof Application) {
            throw new \LogicException('bootstrap/app.php returns no Laravel application');
        }
        $app->loadEnvironmentFrom('.env.specrig');
        $standIns = $this->standIns();
        $app->afterResolving(HttpFactory::class, fn (HttpFactory $factory) => $factory->fake(
            fn (ClientRequest $request) => self::stub($standIns, $request),
        ));
        $this->app = $app;
        try {
            Handlers::kept(fn () => $app->make(ConsoleKernel::class)->bootstrap());
        } catch (\Throwable $e) {
            $this->stop();
            throw $e;
        }
        $app['events']->listen(MessageLogged::class, function (MessageLogged $logged) {
            $this->logs[] = new Logged($logged->level, (string) $logged->message, $logged->context);
        });
        $this->queues($app);
        foreach ($this->resources(Bus::class) as $bus) {
            foreach (array_keys($app->getBindings()) as $abstract) {
                if (str_starts_with($abstract, 'PhpAmqpLib\\Connection\\') && is_a($abstract, AbstractConnection::class, true)) {
                    $app->instance($abstract, $bus->connection());
                }
            }
        }
        $this->clock->follow(function (?\DateTimeImmutable $at) {
            Carbon::setTestNow($at === null ? null : Carbon::instance($at));
            CarbonImmutable::setTestNow($at === null ? null : CarbonImmutable::instance($at));
        });
        foreach ($this->replaced() as $type => $instance) {
            $app->instance($type, $instance);
        }
    }

    protected function keepRunning(string $command): void
    {
        $kernel = $this->app()->make(ConsoleKernel::class);
        foreach ($kernel->all() as $name => $registered) {
            if ($registered instanceof $command) {
                $output = new BufferedOutput();
                if ($kernel->call((string) $name, [], $output) !== 0) {
                    throw new \RuntimeException("{$name}, which running() names, exited: " . trim($output->fetch()));
                }
                return;
            }
        }
        throw new \LogicException("running() names {$command}, which {$this->service()} registers as no command");
    }

    protected function stop(): void
    {
        $app = $this->app;
        $this->app = null;
        $this->queued = null;
        $this->logs = [];
        $app?->flush();
        Facade::clearResolvedInstances();
        Flush::state();
    }

    /** @return array{Response, ?string} */
    private function handle(Request $request): array
    {
        $kernel = $this->app()->make(HttpKernel::class);
        $server = [];
        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}"] = $value;
        }
        $laravel = LaravelRequest::create($request->url, $request->method, [], [], [], $server, $request->body);
        $response = $kernel->handle($laravel);
        $content = $response->getContent();
        if ($content === false) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        }
        $kernel->terminate($laravel, $response);
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $route = $laravel->route();
        $named = $route instanceof Route
            ? Inbound::route($route->uri(), array_keys(array_filter($route->wheres, fn ($where) => in_array($where, ['.*', '.+'], true))))
            : null;
        return [new Response($response->getStatusCode(), $headers, $content), $named];
    }

    private static function stub(StandIns $standIns, ClientRequest $request): PromiseInterface
    {
        $psr = $request->toPsrRequest();
        $headers = [];
        foreach ($psr->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        try {
            $answer = $standIns->send(new Request($psr->getMethod(), (string) $psr->getUri(), $headers, (string) $psr->getBody()));
            return HttpFactory::response($answer->body, $answer->status, $answer->headers);
        } catch (Unreachable $e) {
            $curl = $e->timedOut ? 'cURL error 28: Operation timed out' : 'cURL error 7: Failed to connect';
            return Create::rejectionFor(new ConnectException("{$curl}: {$e->getMessage()}", $psr));
        }
    }

    private function queues(Application $app): void
    {
        $queued = $this->queued = new Queued();
        $app['queue']->addConnector('specrig', fn () => new class($queued) implements ConnectorInterface {
            public function __construct(private readonly Queued $queued)
            {
            }

            public function connect(array $config)
            {
                return new Queue($this->queued, (string) ($config['queue'] ?? 'default'), (bool) ($config['after_commit'] ?? false));
            }
        });
        foreach ($app['config']->get('queue.connections', []) as $name => $connection) {
            if (!in_array($connection['driver'] ?? null, ['sync', 'null', 'deferred', 'background', 'failover'], true)) {
                $app['config']->set("queue.connections.{$name}.driver", 'specrig');
            }
        }
    }
}
