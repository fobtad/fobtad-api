<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the schema in line with the provider portal: provider services and
 * map location (for nearest-provider routing), driver shift/standby, and
 * vehicle service details.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->enum('business_type', ['towing_company', 'vehicle_recovery', 'roadside_assistance', 'fleet_operator'])
                ->nullable()->after('company_name');
            // Services offered, e.g. ["towing"]. Rides will be added here later.
            $table->json('services')->nullable()->after('business_type');
            $table->decimal('latitude', 10, 7)->nullable()->after('state');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->text('description')->nullable()->after('coverage_areas');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->enum('shift', ['morning', 'afternoon', 'night'])->nullable()->after('licence_number');
            $table->enum('status', ['available', 'on_job', 'standby', 'off_duty'])
                ->default('available')->change();
        });

        Schema::table('tow_trucks', function (Blueprint $table) {
            $table->enum('status', ['available', 'on_job', 'maintenance', 'off_duty'])
                ->default('available')->change();
            $table->decimal('capacity_tonnes', 5, 1)->nullable()->after('type');
            $table->unsignedTinyInteger('fuel_level')->default(100)->after('status');
            $table->date('last_service_at')->nullable()->after('fuel_level');
            $table->unsignedInteger('mileage_km')->nullable()->after('last_service_at');
        });
    }

    public function down(): void
    {
        Schema::table('tow_trucks', function (Blueprint $table) {
            $table->dropColumn(['capacity_tonnes', 'fuel_level', 'last_service_at', 'mileage_km']);
            $table->enum('status', ['available', 'on_job', 'maintenance'])
                ->default('available')->change();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('shift');
            $table->enum('status', ['available', 'on_job', 'off_duty'])
                ->default('available')->change();
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'services', 'latitude', 'longitude', 'description']);
        });
    }
};
