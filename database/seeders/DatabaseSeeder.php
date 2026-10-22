<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::table('providers')->insertOrIgnore(['id' => 1, 'name' => 'Ahmad Tea']);
            DB::table('storages')->insertOrIgnore(['id' => 1, 'name' => 'Main warehouse']);
            DB::table('clients')->insertOrIgnore(['id' => 1, 'name' => 'Demo market']);
            DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Ahmad Tea', 'parent_id' => null, 'provider_id' => 1]);
            DB::table('categories')->insertOrIgnore(['id' => 2, 'name' => 'Black Tea', 'parent_id' => 1, 'provider_id' => null]);
            DB::table('categories')->insertOrIgnore(['id' => 3, 'name' => 'Green Tea', 'parent_id' => 1, 'provider_id' => null]);
            DB::table('products')->insertOrIgnore(['id' => 1, 'name' => 'Ahmad Tea Earl Grey, 500g', 'category_id' => 2, 'price' => '20.00']);
            DB::table('products')->insertOrIgnore(['id' => 2, 'name' => 'Ahmad Tea Green Tea, 500g', 'category_id' => 3, 'price' => '25.00']);
        });
    }
}
