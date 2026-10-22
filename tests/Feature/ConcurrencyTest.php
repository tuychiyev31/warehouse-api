<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_orders_and_refunds_cannot_exceed_stock_or_sale(): void
    {
        $this->seed();
        $batch = $this->postJson('/api/purchases', [
            'provider_id' => 1, 'storage_id' => 1,
            'products' => [['id' => 1, 'qty' => 5, 'cost' => '10.00']],
        ])->assertCreated()->json('id');
        $this->assertSame([201, 422], $this->race('/api/orders', [
            'client_id' => 1, 'products' => [['id' => 1, 'qty' => 4]],
        ]));
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 1]);
        $this->assertDatabaseCount('orders', 1);
        $order = DB::table('order_items')->first();
        $this->assertSame([201, 422], $this->race('/api/orders/'.$order->order_id.'/refunds', [
            'items' => [['id' => $order->id, 'qty' => 4]],
        ]));
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 5]);
        $this->assertDatabaseCount('client_refunds', 1);
        $this->assertSame([201, 422], $this->race('/api/batches/'.$batch.'/refunds', [
            'products' => [['id' => 1, 'qty' => 5]],
        ]));
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 0]);
        $this->assertDatabaseCount('purchase_refunds', 1);
    }

    private function race(string $uri, array $body): array
    {
        $script = <<<'PHPWORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create($argv[1], 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], $argv[2]);
echo "ready\n";
flush();
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
PHPWORKER;
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
        ];
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('products')->where('id', 1)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-r', $script, $uri, json_encode($body)], base_path(), $environment, timeout: 15);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
            }
        } finally {
            DB::rollBack();
        }
        $statuses = [];
        try {
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $statuses[] = (int) trim(str_replace('ready', '', $worker->getOutput()));
            }
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
        sort($statuses);

        return $statuses;
    }
}
