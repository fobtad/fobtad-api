<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_wallet_id')
                ->constrained('provider_wallets')
                ->onDelete('cascade');
            $table->string('account_number', 20);
            $table->string('account_name', 191);
            $table->string('bank_name', 191);
            $table->string('bank_code', 20)->nullable();
            $table->string('paystack_reference', 191)->nullable();
            $table->string('customer_code', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_virtual_accounts');
    }
};