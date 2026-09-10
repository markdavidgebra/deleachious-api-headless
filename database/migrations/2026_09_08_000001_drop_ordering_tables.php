<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qr_codes')) {
            $orderQrIds = DB::table('qr_codes')
                ->where(function ($query) {
                    $query->where('type', 'order')
                        ->orWhere('purpose', 'order_pickup')
                        ->orWhere('qrable_type', 'like', '%Order');
                })
                ->pluck('id');

            if ($orderQrIds->isNotEmpty() && Schema::hasTable('qr_scans')) {
                DB::table('qr_scans')->whereIn('qr_code_id', $orderQrIds)->delete();
            }

            DB::table('qr_codes')
                ->where(function ($query) {
                    $query->where('type', 'order')
                        ->orWhere('purpose', 'order_pickup')
                        ->orWhere('qrable_type', 'like', '%Order');
                })
                ->delete();
        }

        if (Schema::hasTable('loyalty_points')) {
            DB::table('loyalty_points')
                ->where('reference_type', 'like', '%Order')
                ->update([
                    'reference_type' => null,
                    'reference_id' => null,
                ]);
        }

        if (Schema::hasTable('qr_scans')) {
            DB::table('qr_scans')->where('action', 'verify_order')->delete();
        }

        Schema::dropIfExists('order_item_addons');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('orders');

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')
                ->where(function ($query) {
                    $query->whereIn('name', ['orders', 'transactions'])
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
        // Ordering was removed on purpose.
    }
};
