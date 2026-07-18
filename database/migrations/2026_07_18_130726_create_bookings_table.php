<?php
// create_bookings_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('paramedic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ambulance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tow_truck_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', [
                'emergency', 'scheduled', 'maternal',
                'towing', 'recurring'
            ]);
            $table->enum('status', [
                'pending', 'accepted', 'assigned',
                'en_route', 'arrived', 'completed', 'cancelled'
            ])->default('pending');
            $table->string('pickup_address');
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('destination_address')->nullable();
            $table->decimal('destination_latitude', 10, 7)->nullable();
            $table->decimal('destination_longitude', 10, 7)->nullable();
            $table->string('incident_type')->nullable();
            $table->string('ride_type')->nullable();
            $table->boolean('is_high_risk')->default(false);
            $table->boolean('needs_wheelchair')->default(false);
            $table->integer('weeks_pregnant')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->decimal('fare', 10, 2)->nullable();
            $table->integer('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('bookings');
    }
};