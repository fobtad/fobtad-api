<?php
// create_tow_trucks_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tow_trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number')->unique();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->enum('type', ['flatbed', 'wheel_lift', 'heavy_recovery'])->default('flatbed');
            $table->enum('status', ['available', 'on_job', 'maintenance'])->default('available');
            $table->boolean('flagged_for_maintenance')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('tow_trucks');
    }
};