<?php
// create_providers_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('address');
            $table->string('lga')->nullable();
            $table->string('state')->default('Lagos');
            $table->string('cac_number')->nullable();
            $table->text('coverage_areas')->nullable();
            $table->integer('fleet_size')->default(0);
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->string('logo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('providers');
    }
};