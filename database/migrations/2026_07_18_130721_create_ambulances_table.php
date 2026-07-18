<?php
// create_ambulances_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ambulances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number')->unique();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->enum('type', ['standard', 'advanced', 'neonatal', 'wheelchair'])->default('standard');
            $table->enum('status', ['available', 'on_trip', 'maintenance'])->default('available');
            $table->integer('fuel_level')->default(100);
            $table->boolean('flagged_for_maintenance')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('ambulances');
    }
};