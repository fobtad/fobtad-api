<?php
// create_paramedics_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('paramedics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('licence_number')->nullable();
            $table->enum('status', ['available', 'on_trip', 'off_duty'])->default('available');
            $table->integer('total_trips')->default(0);
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('paramedics');
    }
};