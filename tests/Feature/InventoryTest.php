<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function purchase(int $qty = 10, string $cost = '10.00', int $storage = 1): array
    {
        return $this->postJson('/api/purchases', [
            'provider_id' => 1, 'storage_id' => $storage,
            'products' => [['id' => 1, 'qty' => $qty, 'cost' => $cost]],
        ])->assertCreated()->json();
    }

    private function order(int $qty): array
    {
        return $this->postJson('/api/orders', [
            'client_id' => 1, 'products' => [['id' => 1, 'qty' => $qty]],
        ])->assertCreated()->json();
    }

    public function test_purchase_and_available_products(): void
    {
        $this->purchase();
        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.qty', 10)
            ->assertJsonPath('data.0.category_name', 'Black Tea')
            ->assertJsonPath('data.0.price', '20.00');
        $this->assertDatabaseHas('batch_items', ['qty' => 10, 'remaining_qty' => 10, 'cost' => 10]);
    }

    public function test_fifo_splits_across_batches_and_storages(): void
    {
        DB::table('storages')->insert(['id' => 2, 'name' => 'Second warehouse']);
        $this->travelTo(now()->setDate(2026, 1, 1));
        $first = $this->purchase(3, '10.00');
        $this->travel(1)->days();
        $second = $this->purchase(5, '12.00', 2);
        $order = $this->order(6);
        $this->assertCount(2, $order['items']);
        $this->assertSame($first['items'][0]['id'], $order['items'][0]['batch_item_id']);
        $this->assertSame(3, $order['items'][0]['qty']);
        $this->assertSame($second['items'][0]['id'], $order['items'][1]['batch_item_id']);
        $this->getJson('/api/products?storage_id=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/products?storage_id=2')->assertJsonPath('data.0.qty', 2);
        $profit = $this->getJson('/api/batches/profit')->assertOk()->json('data');
        $this->assertEquals(30, $profit[0]['profit']);
        $this->assertEquals(24, $profit[1]['profit']);
    }

    public function test_insufficient_stock_rolls_back_the_entire_order(): void
    {
        $this->purchase(2);
        $this->postJson('/api/orders', ['client_id' => 1, 'products' => [
            ['id' => 1, 'qty' => 2], ['id' => 2, 'qty' => 1],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseHas('batch_items', ['product_id' => 1, 'remaining_qty' => 2]);
    }

    public function test_purchase_refunds_only_available_stock_and_cannot_be_repeated(): void
    {
        $batch = $this->purchase(10);
        $this->order(4);
        $url = '/api/batches/'.$batch['id'].'/refunds';
        $this->postJson($url, ['products' => [['id' => 1, 'qty' => 7]]])->assertUnprocessable();
        $this->postJson($url, ['products' => [['id' => 1, 'qty' => 2]]])->assertCreated();
        $this->postJson($url, ['products' => [['id' => 1, 'qty' => 4]]])->assertCreated();
        $this->postJson($url, ['products' => [['id' => 1, 'qty' => 1]]])->assertUnprocessable();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->assertEquals(40, $this->getJson('/api/batches/profit')->json('data.0.profit'));
    }

    public function test_client_refunds_restore_original_batch_and_reverse_original_margin(): void
    {
        $this->purchase(5, '10.10');
        DB::table('products')->where('id', 1)->update(['price' => '20.20']);
        $order = $this->order(5);
        DB::table('products')->where('id', 1)->update(['price' => '99.00']);
        $url = '/api/orders/'.$order['id'].'/refunds';
        $item = $order['items'][0]['id'];
        $this->postJson($url, ['items' => [['id' => $item, 'qty' => 2]]])->assertCreated();
        $this->getJson('/api/products')->assertJsonPath('data.0.qty', 2);
        $this->assertEquals('30.30', $this->getJson('/api/batches/profit')->json('data.0.profit'));
        $this->postJson($url, ['items' => [['id' => $item, 'qty' => 4]]])->assertUnprocessable();
        $this->postJson($url, ['items' => [['id' => $item, 'qty' => 3]]])->assertCreated();
        $this->assertEquals(0, $this->getJson('/api/batches/profit')->json('data.0.profit'));
        $this->postJson($url, ['items' => [['id' => $item, 'qty' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 5]);
    }

    public function test_historical_stock_includes_only_events_through_requested_day(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $batch = $this->purchase(10);
        $this->travel(1)->days();
        $order = $this->order(6);
        $this->travel(1)->days();
        $this->postJson('/api/orders/'.$order['id'].'/refunds', ['items' => [
            ['id' => $order['items'][0]['id'], 'qty' => 2],
        ]])->assertCreated();
        $this->travel(1)->days();
        $this->postJson('/api/batches/'.$batch['id'].'/refunds', ['products' => [['id' => 1, 'qty' => 3]]])->assertCreated();
        $this->getJson('/api/storage/quantities?date=2025-12-31')->assertJsonCount(0, 'data');
        foreach (['2026-01-01' => 10, '2026-01-02' => 4, '2026-01-03' => 6, '2026-01-04' => 3] as $date => $qty) {
            $this->getJson('/api/storage/quantities?date='.$date)->assertOk()->assertJsonPath('data.0.qty', $qty);
        }
        $this->getJson('/api/products')->assertJsonPath('data.0.qty', 3);
    }

    public function test_provider_must_match_root_category(): void
    {
        DB::table('providers')->insert(['id' => 2, 'name' => 'Wrong provider']);
        $this->postJson('/api/purchases', ['provider_id' => 2, 'storage_id' => 1,
            'products' => [['id' => 1, 'qty' => 1, 'cost' => '10.00']],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('batches', 0);
        DB::table('categories')->insert(['id' => 4, 'name' => 'Earl Grey', 'parent_id' => 2, 'provider_id' => null]);
        DB::table('products')->where('id', 1)->update(['category_id' => 4]);
        $this->purchase();
    }

    public function test_rejects_invalid_requests_and_unknown_records(): void
    {
        foreach ([0, -1, 1.5] as $qty) {
            $this->postJson('/api/orders', ['client_id' => 1, 'products' => [['id' => 1, 'qty' => $qty]]])->assertUnprocessable();
        }
        $this->postJson('/api/orders', ['client_id' => 1, 'products' => [
            ['id' => 1, 'qty' => 1], ['id' => 1, 'qty' => 1],
        ]])->assertUnprocessable();
        $this->postJson('/api/orders', ['client_id' => 1, 'products' => [['id' => 999, 'qty' => 1]]])->assertUnprocessable();
        $this->postJson('/api/orders', ['client_id' => 1, 'products' => [['id' => 1, 'qty' => 1, 'batch_id' => 1]]])->assertUnprocessable();
        $this->postJson('/api/purchases', ['provider_id' => 1, 'storage_id' => 1,
            'products' => [['id' => 1, 'qty' => 1, 'cost' => '1.001']],
        ])->assertUnprocessable();
        $this->getJson('/api/storage/quantities?date=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/storage/quantities')->assertUnprocessable();
        $this->postJson('/api/batches/999/refunds', [])->assertNotFound();
        $this->postJson('/api/orders/999/refunds', [])->assertNotFound();
    }

    public function test_refund_cannot_use_another_orders_allocation_and_is_atomic(): void
    {
        $this->purchase(10);
        $first = $this->order(3);
        $second = $this->order(2);
        $this->postJson('/api/orders/'.$first['id'].'/refunds', ['items' => [
            ['id' => $first['items'][0]['id'], 'qty' => 1],
            ['id' => $second['items'][0]['id'], 'qty' => 1],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('client_refunds', 0);
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 5]);
        $this->assertDatabaseHas('order_items', ['id' => $first['items'][0]['id'], 'refunded_qty' => 0]);
    }

    public function test_returned_stock_can_be_resold_without_counting_refunds_twice(): void
    {
        $batch = $this->purchase(2);
        $first = $this->order(2);
        $this->postJson('/api/orders/'.$first['id'].'/refunds', ['items' => [
            ['id' => $first['items'][0]['id'], 'qty' => 2],
        ]])->assertCreated();
        $this->order(1);
        $this->postJson('/api/batches/'.$batch['id'].'/refunds', ['products' => [['id' => 1, 'qty' => 1]]])->assertCreated();
        $this->assertEquals(10, $this->getJson('/api/batches/profit')->json('data.0.profit'));
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->getJson('/api/storage/quantities?date='.now()->format('Y-m-d'))->assertJsonPath('data.0.qty', 0);
    }

    public function test_unsold_batches_have_zero_profit_and_full_purchase_refund_is_supported(): void
    {
        $batch = $this->purchase(4);
        $this->assertEquals(0, $this->getJson('/api/batches/profit')->json('data.0.profit'));
        $this->postJson('/api/batches/'.$batch['id'].'/refunds', ['products' => [['id' => 1, 'qty' => 4]]])->assertCreated();
        $this->assertDatabaseHas('batch_items', ['remaining_qty' => 0]);
    }
}
