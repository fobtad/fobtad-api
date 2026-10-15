<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the schema in line with the hospital portal:
 * mortuary bookings, paramedic role/shift/standby, fleet service details,
 * and extra hospital profile fields.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('type', [
                'emergency', 'scheduled', 'maternal',
                'towing', 'recurring', 'mortuary',
            ])->change();
        });

        Schema::table('paramedics', function (Blueprint $table) {
            $table->enum('role', ['senior_paramedic', 'paramedic', 'emergency_technician'])
                ->default('paramedic')->after('phone');
            $table->enum('shift', ['morning', 'afternoon', 'night'])->nullable()->after('role');
            $table->enum('status', ['available', 'on_trip', 'standby', 'off_duty'])
                ->default('available')->change();
        });

        Schema::table('ambulances', function (Blueprint $table) {
            $table->enum('status', ['available', 'on_trip', 'maintenance', 'off_duty'])
                ->default('available')->change();
            $table->date('last_service_at')->nullable()->after('fuel_level');
            $table->unsignedInteger('mileage_km')->nullable()->after('last_service_at');
        });

        Schema::table('hospitals', function (Blueprint $table) {
            $table->enum('facility_type', ['government', 'private', 'clinic', 'teaching'])
                ->nullable()->after('name');
            $table->string('cac_number')->nullable()->after('licence_number');
            $table->string('website')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropColumn(['facility_type', 'cac_number', 'website']);
        });

        Schema::table('ambulances', function (Blueprint $table) {
            $table->dropColumn(['last_service_at', 'mileage_km']);
            $table->enum('status', ['available', 'on_trip', 'maintenance'])
                ->default('available')->change();
        });

        Schema::table('paramedics', function (Blueprint $table) {
            $table->dropColumn(['role', 'shift']);
            $table->enum('status', ['available', 'on_trip', 'off_duty'])
                ->default('available')->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('type', [
                'emergency', 'scheduled', 'maternal',
                'towing', 'recurring',
            ])->change();
        });
    }
};
