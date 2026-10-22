<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['providers', 'storages', 'clients'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
        }
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 12, 2);
        });
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_id')->constrained()->restrictOnDelete();
            $table->dateTime('purchased_at', 6)->index();
        });
        Schema::create('batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('remaining_qty');
            $table->decimal('cost', 12, 2);
            $table->unique(['batch_id', 'product_id']);
            $table->index(['product_id', 'remaining_qty']);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->dateTime('sold_at', 6)->index();
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('refunded_qty')->default(0);
            $table->decimal('price', 12, 2);
            $table->unique(['order_id', 'batch_item_id']);
        });
        Schema::create('purchase_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->dateTime('refunded_at', 6)->index();
        });
        Schema::create('client_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->dateTime('refunded_at', 6)->index();
        });
    }

    public function down(): void
    {
        foreach (['client_refunds', 'purchase_refunds', 'order_items', 'orders', 'batch_items', 'batches', 'products', 'categories', 'clients', 'storages', 'providers'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
