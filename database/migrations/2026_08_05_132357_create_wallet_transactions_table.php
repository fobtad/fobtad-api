<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2)->default(0.00);
            $table->decimal('balance_after', 12, 2)->default(0.00);
            $table->string('reference', 191)->unique();
            $table->string('description', 191)->nullable();
            $table->enum('source', [
                'bank_transfer',
                'paystack_checkout',
                'booking_payment',
                'refund',
                'reversal',
            ])->default('bank_transfer');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};