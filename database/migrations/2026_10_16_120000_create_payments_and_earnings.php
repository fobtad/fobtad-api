<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking payments (wallet first, card for the remainder), hospital/provider
 * earnings after commission, weekly payouts, and admin-editable settings.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'waived'])
                ->default('unpaid')->after('fare');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['wallet', 'card'])->default('card')->after('amount');
        });

        Schema::create('earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('payee_type', ['hospital', 'provider']);
            $table->unsignedBigInteger('payee_id');
            $table->decimal('gross', 10, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission', 10, 2);
            $table->decimal('net', 10, 2);
            $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->timestamps();
            $table->index(['payee_type', 'payee_id']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('payee_type', ['hospital', 'provider']);
            $table->unsignedBigInteger('payee_id');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['requested', 'paid', 'rejected'])->default('requested');
            $table->string('bank_name');
            $table->string('account_number', 20);
            $table->string('account_name');
            $table->string('requested_by')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['payee_type', 'payee_id']);
        });

        foreach (['hospitals', 'providers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('bank_name')->nullable();
                $table->string('account_number', 20)->nullable();
                $table->string('account_name')->nullable();
            });
        }

        // The old placeholder "virtual accounts" had made-up numbers. Hide them.
        DB::table('virtual_accounts')->where('paystack_reference', 'like', 'MOCK-%')
            ->update(['status' => 'inactive', 'is_primary' => false]);
    }

    public function down(): void
    {
        foreach (['hospitals', 'providers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['bank_name', 'account_number', 'account_name']);
            });
        }
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('earnings');
        Schema::table('payments', fn(Blueprint $table) => $table->dropColumn('method'));
        Schema::table('bookings', fn(Blueprint $table) => $table->dropColumn(['payment_status', 'amount_paid']));
        Schema::dropIfExists('platform_settings');
    }
};
