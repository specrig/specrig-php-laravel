<?php

declare(strict_types=1);

namespace Specrig\Laravel\Tests;

use App\Billing;
use App\Console\Commands\ConsumePayments;
use App\Models\Order;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Specrig\Amqp\Bus;
use Specrig\Backend;
use Specrig\Laravel\Harness;
use Specrig\Postgres;
use Specrig\Rig;

final class Shop extends Harness
{
    /** @var array<string, string|null> */
    public static array $constants = [];

    /** @var list<class-string> */
    public static array $running = [];

    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.shop.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Postgres $database = new Postgres('DB_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public static function tests(): string
    {
        return dirname(__DIR__, 3) . '/samples/shop/tests';
    }

    public function service(): string
    {
        return 'shop';
    }

    protected function constants(): array
    {
        return ['DB_CONNECTION' => 'pgsql', ...self::$constants];
    }

    protected function running(): array
    {
        return self::$running;
    }

    protected function allow(): array
    {
        return [Billing::class];
    }
}

final class HarnessTest extends TestCase
{
    private Shop $shop;

    protected function setUp(): void
    {
        Shop::$constants = [];
        Shop::$running = [ConsumePayments::class];
        $this->shop = new Shop();
    }

    protected function tearDown(): void
    {
        $this->shop->dispose();
    }

    public static function tearDownAfterClass(): void
    {
        Rig::endRun();
    }

    private function placed(int $order): void
    {
        $this->shop->boot();
        Order::create(['id' => $order, 'amount' => 120, 'paid' => false]);
    }

    public function testACallNoStepAnsweredIsAFailureNamed(): void
    {
        $this->placed(7);

        $this->shop->http()->get('/api/orders/7');

        $this->assertSame(['billing was called where no step declared its answer: GET /invoices/7'], $this->shop->failures());
    }

    public function testAnAnswerTheContractDoesNotAllowIsAFailure(): void
    {
        $this->placed(7);
        $this->shop->billing->reply('GET', '/invoices/7', 200, ['amount' => 'a lot']);

        $this->shop->http()->get('/api/orders/7');

        $this->assertStringContainsString("what billing's contract, ../docs/billing.yaml, does not allow: GET /invoices/7 answered 200", $this->shop->failures()[0] ?? '');
    }

    public function testARequestIsTracedByTheRouteItMatchedAndItsRefusal(): void
    {
        $this->shop->http()->get('/api/orders/8');

        $record = $this->shop->trace->records()[0];
        $this->assertSame(['in', 'http', 'GET /api/orders/{id}', 'HTTP 404 order_not_found'], [$record->dir, $record->kind, $record->name, $record->error]);
    }

    public function testARequestPastTheStandInsIsRefusedBeforeItLeavesAndNamed(): void
    {
        $this->shop->boot();

        try {
            (new Client())->get('https://maps.example.com/tiles/1', ['timeout' => 5]);
            $this->fail('the request went past the stand-ins');
        } catch (GuzzleException) {
        }

        $this->assertSame(['requests to an origin Shop does not name: https://maps.example.com, past the stand-ins'], $this->shop->failures());
    }

    public function testASettingADeveloperExportedDoesNotReachTheCase(): void
    {
        putenv('QUEUE_CONNECTION=sync');
        $_SERVER['QUEUE_CONNECTION'] = $_ENV['QUEUE_CONNECTION'] = 'sync';

        $this->shop->boot();

        $this->assertSame('database', $this->shop->app()['config']->get('queue.default'));
    }

    public function testAnAnswerTheServiceDoesNotReadFailsTheBoot(): void
    {
        Shop::$constants = ['BILING_URL' => 'https://billing.shop.test'];

        $this->expectExceptionMessage('Shop answers BILING_URL, which shop does not read');
        $this->shop->boot();
    }

    public function testAConsumerRunningDoesNotNameFailsTheBoot(): void
    {
        Shop::$running = [];

        $this->expectExceptionMessage('App\Console\Commands\ConsumePayments binds a queue and running() does not name it');
        $this->shop->boot();
    }

    public function testRunningNamesOnlyTheServicesCommands(): void
    {
        Shop::$running = [ConsumePayments::class, Billing::class];

        $this->expectExceptionMessage('running() names App\Billing, which shop registers as no command');
        $this->shop->boot();
    }

    public function testASeamTheHarnessDoesNotGrantIsRefused(): void
    {
        $this->expectExceptionMessage('App\Announcer is not granted: allow() in Shop does not name it');
        $this->shop->replace(\App\Announcer::class, new \stdClass());
    }

    public function testAGrantedSeamIsWhatTheServiceGets(): void
    {
        $billing = new Billing();
        $this->shop->replace(Billing::class, $billing);

        $this->assertSame($billing, $this->shop->get(Billing::class));
    }

    public function testEveryCaseStartsFromAnEmptyDatabase(): void
    {
        $this->placed(7);
        $this->shop->dispose();

        $this->shop = new Shop();
        $this->shop->boot();

        $this->assertNull(Order::find(7));
    }

    public function testADatabaseThatFailsRefusesTheServiceUntilTheNextCase(): void
    {
        $this->placed(7);
        $this->shop->database->failWith();

        try {
            Order::find(7);
            $this->fail('the database answered');
        } catch (QueryException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->shop->dispose();

        $this->shop = new Shop();
        $this->shop->boot();
        $this->assertNull(Order::find(7));
    }
}
