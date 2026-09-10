<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = Schema::hasTable('point_items') ? 'point_items' : (Schema::hasTable('rewards') ? 'rewards' : null);

        if ($catalog && Schema::hasTable('products') && Schema::hasColumn($catalog, 'product_id')) {
            $items = DB::table($catalog)->whereNotNull('product_id')->get(['id', 'product_id', 'image']);
            foreach ($items as $item) {
                if (! empty($item->image)) {
                    continue;
                }
                $product = DB::table('products')->where('id', $item->product_id)->first();
                if ($product && ! empty($product->image)) {
                    DB::table($catalog)->where('id', $item->id)->update(['image' => $product->image]);
                }
            }
        }

        if ($catalog && Schema::hasColumn($catalog, 'product_id')) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $replacement = $catalog.'_without_product';

                Schema::disableForeignKeyConstraints();
                try {
                    Schema::dropIfExists($replacement);
                    Schema::create($replacement, function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->text('description')->nullable();
                        $table->integer('points_required');
                        $table->string('type');
                        $table->decimal('discount_value', 10, 2)->nullable();
                        $table->string('image')->nullable();
                        $table->boolean('is_active')->default(true);
                        $table->date('expires_at')->nullable();
                        $table->timestamps();
                    });

                    DB::statement(
                        "INSERT INTO {$replacement} (id, name, description, points_required, type, discount_value, image, is_active, expires_at, created_at, updated_at)
                         SELECT id, name, description, points_required, type, discount_value, image, is_active, expires_at, created_at, updated_at FROM {$catalog}"
                    );

                    Schema::drop($catalog);
                    Schema::rename($replacement, $catalog);
                } finally {
                    Schema::enableForeignKeyConstraints();
                }

                $catalog = null;
            }
        }

        if ($catalog && Schema::hasColumn($catalog, 'product_id')) {
            $indexNames = collect(Schema::getIndexes($catalog))->pluck('name');
            $foreignNames = collect(Schema::getForeignKeys($catalog))->pluck('name');

            Schema::table($catalog, function (Blueprint $table) use ($indexNames, $foreignNames) {
                foreach ($foreignNames as $name) {
                    if (is_string($name) && str_contains($name, 'product_id')) {
                        $table->dropForeign($name);
                    }
                }
                foreach ($indexNames as $name) {
                    if (is_string($name) && str_contains($name, 'product_id')) {
                        $table->dropIndex($name);
                    }
                }
                $table->dropColumn('product_id');
            });
        }

        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');

        Schema::dropIfExists('order_item_addons');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('topups');
        Schema::dropIfExists('wallet_idempotency_keys');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('wallet_settings');

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')
                ->where(function ($query) {
                    $query->whereIn('name', ['products', 'orders', 'transactions'])
                        ->orWhere('name', 'like', 'products.%')
                        ->orWhere('name', 'like', 'orders.%')
                        ->orWhere('name', 'like', 'transactions.%');
                })
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                if (Schema::hasTable('role_has_permissions')) {
                    DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
                }
                if (Schema::hasTable('model_has_permissions')) {
                    DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
                }
                DB::table('permissions')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // Menu and ordering were removed on purpose.
    }
};
