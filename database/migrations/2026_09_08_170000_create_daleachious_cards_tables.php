<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daleachious_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default('0.00');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('daleachious_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daleachious_card_id')->constrained('daleachious_cards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2)->nullable();
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->string('status');
            $table->string('reference')->unique();
            $table->string('payment_method')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['daleachious_card_id', 'status']);
        });

        if (Schema::hasTable('permissions')) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $guard = AdminPermissions::GUARD;

            foreach (['card', 'card.confirm'] as $name) {
                Permission::findOrCreate($name, $guard);
            }

            foreach (['staff', 'cashier', 'admin', 'super_admin', 'developer'] as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->first();
                if (! $role) {
                    continue;
                }
                $role->givePermissionTo(['card', 'card.confirm']);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daleachious_card_transactions');
        Schema::dropIfExists('daleachious_cards');
    }
};
