<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'storage_id' => ['required', 'integer', 'exists:storages,id'],
            ...$this->productRules(),
            'products.*.cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $products = $this->lockProducts(array_column($data['products'], 'id'));
            $categories = DB::table('categories')->get()->keyBy('id');
            foreach ($products as $product) {
                $category = $categories->get($product->category_id);
                $seen = [];
                while ($category && $category->parent_id !== null) {
                    if (isset($seen[$category->id]) || $category->provider_id !== null) {
                        $this->invalid('Invalid category tree. Only roots may have a provider.');
                    }
                    $seen[$category->id] = true;
                    $category = $categories->get($category->parent_id);
                }
                if (! $category || (int) $category->provider_id !== (int) $data['provider_id']) {
                    $this->invalid('Product category does not belong to this provider.');
                }
            }
            $batch = DB::table('batches')->insertGetId([
                'provider_id' => $data['provider_id'], 'storage_id' => $data['storage_id'], 'purchased_at' => now(),
            ]);
            DB::table('batch_items')->insert(array_map(fn ($item) => [
                'batch_id' => $batch, 'product_id' => $item['id'], 'qty' => $item['qty'],
                'remaining_qty' => $item['qty'], 'cost' => $item['cost'],
            ], $data['products']));

            return ['id' => $batch, 'items' => DB::table('batch_items')->where('batch_id', $batch)->get()];
        }, 3);

        return response()->json($result, 201);
    }

    public function refundPurchase(Request $request, int $batch): JsonResponse
    {
        abort_unless(DB::table('batches')->where('id', $batch)->exists(), 404);
        $data = $request->validate($this->productRules());
        $refunds = DB::transaction(function () use ($data, $batch) {
            $this->lockProducts(array_column($data['products'], 'id'));
            $items = DB::table('batch_items')->where('batch_id', $batch)
                ->whereIn('product_id', array_column($data['products'], 'id'))
                ->lockForUpdate()->get()->keyBy('product_id');
            $refunds = [];
            $time = now();
            foreach ($data['products'] as $product) {
                $item = $items->get($product['id']);
                if (! $item || $product['qty'] > $item->remaining_qty) {
                    $this->invalid('Refund exceeds available quantity in this batch.');
                }
                DB::table('batch_items')->where('id', $item->id)->decrement('remaining_qty', $product['qty']);
                $refunds[] = ['batch_item_id' => $item->id, 'qty' => $product['qty'], 'refunded_at' => $time];
            }
            DB::table('purchase_refunds')->insert($refunds);

            return $refunds;
        }, 3);

        return response()->json(['batch_id' => $batch, 'refunds' => $refunds], 201);
    }

    public function available(Request $request): JsonResponse
    {
        $data = $request->validate(['storage_id' => ['sometimes', 'integer', 'exists:storages,id']]);
        $stock = DB::table('batch_items as bi')->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->when(isset($data['storage_id']), fn ($query) => $query->where('b.storage_id', $data['storage_id']))
            ->selectRaw('bi.product_id, SUM(bi.remaining_qty) as qty')->groupBy('bi.product_id')->havingRaw('SUM(bi.remaining_qty) > 0');
        $products = DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->joinSub($stock, 'stock', 'stock.product_id', '=', 'p.id')->orderBy('p.id')
            ->get(['p.id', 'p.name', 'c.name as category_name', 'p.price', 'stock.qty'])
            ->map(function ($product) {
                $product->qty = (int) $product->qty;

                return $product;
            });

        return response()->json(['data' => $products]);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            ...$this->productRules(),
            'products.*.batch_id' => ['prohibited'],
        ]);
        $result = DB::transaction(function () use ($data) {
            $products = $this->lockProducts(array_column($data['products'], 'id'));
            $stock = DB::table('batch_items as bi')->join('batches as b', 'b.id', '=', 'bi.batch_id')
                ->whereIn('bi.product_id', $products->keys())->where('bi.remaining_qty', '>', 0)
                ->orderBy('b.purchased_at')->orderBy('b.id')->orderBy('bi.id')
                ->select('bi.*')->lockForUpdate()->get()->groupBy('product_id');
            $order = DB::table('orders')->insertGetId(['client_id' => $data['client_id'], 'sold_at' => now()]);
            $allocations = [];
            foreach ($data['products'] as $product) {
                $needed = (int) $product['qty'];
                foreach ($stock->get($product['id'], collect()) as $item) {
                    $qty = min($needed, $item->remaining_qty);
                    $allocations[] = [
                        'order_id' => $order, 'batch_item_id' => $item->id, 'qty' => $qty,
                        'price' => $products[$product['id']]->price,
                    ];
                    DB::table('batch_items')->where('id', $item->id)->decrement('remaining_qty', $qty);
                    $needed -= $qty;
                    if ($needed === 0) {
                        break;
                    }
                }
                if ($needed > 0) {
                    $this->invalid('Insufficient stock for product '.$product['id'].'.');
                }
            }
            DB::table('order_items')->insert($allocations);

            return ['id' => $order, 'items' => DB::table('order_items')->where('order_id', $order)->get()];
        }, 3);

        return response()->json($result, 201);
    }

    public function refundOrder(Request $request, int $order): JsonResponse
    {
        abort_unless(DB::table('orders')->where('id', $order)->exists(), 404);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        // Resolve immutable product references before opening the transaction.
        $productIds = DB::table('order_items as oi')->join('batch_items as bi', 'bi.id', '=', 'oi.batch_item_id')
            ->where('oi.order_id', $order)->whereIn('oi.id', array_column($data['items'], 'id'))
            ->pluck('bi.product_id')->unique()->all();
        $refunds = DB::transaction(function () use ($data, $order, $productIds) {
            $this->lockProducts($productIds);
            $items = DB::table('order_items')->where('order_id', $order)
                ->whereIn('id', array_column($data['items'], 'id'))->lockForUpdate()->get()->keyBy('id');
            $refunds = [];
            $time = now();
            foreach ($data['items'] as $refund) {
                $item = $items->get($refund['id']);
                if (! $item || $refund['qty'] > $item->qty - $item->refunded_qty) {
                    $this->invalid('Refund exceeds unrefunded quantity in this order.');
                }
                DB::table('order_items')->where('id', $item->id)->increment('refunded_qty', $refund['qty']);
                DB::table('batch_items')->where('id', $item->batch_item_id)->increment('remaining_qty', $refund['qty']);
                $refunds[] = ['order_item_id' => $item->id, 'qty' => $refund['qty'], 'refunded_at' => $time];
            }
            DB::table('client_refunds')->insert($refunds);

            return $refunds;
        }, 3);

        return response()->json(['order_id' => $order, 'refunds' => $refunds], 201);
    }

    public function quantities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'storage_id' => ['sometimes', 'integer', 'exists:storages,id'],
        ]);
        $until = CarbonImmutable::parse($data['date'])->addDay()->startOfDay();
        $purchases = DB::table('batch_items as bi')->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->where('b.purchased_at', '<', $until)->selectRaw('b.storage_id, bi.product_id, bi.qty as delta');
        $sales = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('batch_items as bi', 'bi.id', '=', 'oi.batch_item_id')->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->where('o.sold_at', '<', $until)->selectRaw('b.storage_id, bi.product_id, -CAST(oi.qty AS SIGNED) as delta');
        $purchaseRefunds = DB::table('purchase_refunds as r')->join('batch_items as bi', 'bi.id', '=', 'r.batch_item_id')
            ->join('batches as b', 'b.id', '=', 'bi.batch_id')->where('r.refunded_at', '<', $until)
            ->selectRaw('b.storage_id, bi.product_id, -CAST(r.qty AS SIGNED) as delta');
        $clientRefunds = DB::table('client_refunds as r')->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')
            ->join('batch_items as bi', 'bi.id', '=', 'oi.batch_item_id')->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->where('r.refunded_at', '<', $until)->selectRaw('b.storage_id, bi.product_id, r.qty as delta');
        $movements = $purchases->unionAll($sales)->unionAll($purchaseRefunds)->unionAll($clientRefunds);
        $rows = DB::query()->fromSub($movements, 'm')->join('products as p', 'p.id', '=', 'm.product_id')
            ->when(isset($data['storage_id']), fn ($query) => $query->where('m.storage_id', $data['storage_id']))
            ->selectRaw('m.storage_id, p.id, p.name, SUM(m.delta) as qty')
            ->groupBy('m.storage_id', 'p.id', 'p.name')->orderBy('m.storage_id')->orderBy('p.id')
            ->get()->map(function ($row) {
                $row->qty = (int) $row->qty;

                return $row;
            });

        return response()->json(['date' => $data['date'], 'data' => $rows]);
    }

    public function profit(): JsonResponse
    {
        // Aggregate sale allocations before joining batches, so refunds cannot multiply rows.
        $profits = DB::table('order_items as oi')->join('batch_items as bi', 'bi.id', '=', 'oi.batch_item_id')
            ->selectRaw('bi.batch_id, SUM((oi.qty - oi.refunded_qty) * (oi.price - bi.cost)) as profit')
            ->groupBy('bi.batch_id');
        $rows = DB::table('batches as b')->leftJoinSub($profits, 'p', 'p.batch_id', '=', 'b.id')
            ->selectRaw('b.id as batch_id, COALESCE(p.profit, 0) as profit')->orderBy('b.id')->get();

        return response()->json(['data' => $rows]);
    }

    private function productRules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'products.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    private function lockProducts(array $ids): Collection
    {
        $products = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            $this->invalid('One or more products do not exist.');
        }

        return $products;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['products' => $message]);
    }
}
