<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->onDelete('cascade');
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->string('currency', 3)->default('NGN');
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->unique('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_wallets');
    }
};