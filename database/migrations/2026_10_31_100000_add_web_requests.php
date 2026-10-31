<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency requests made on fobtad.com without the app. They go to FOBTAD
 * admin first; admin calls the person back, then sends it to a hospital.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 10)->default('app')->after('type');
            $table->string('tracking_token', 64)->nullable()->unique()->after('reference');
            $table->timestamp('confirmed_at')->nullable()->after('scheduled_at');
            $table->index(['source', 'status']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('web_blocked_at')->nullable()->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropUnique(['tracking_token']);
            $table->dropColumn(['source', 'tracking_token', 'confirmed_at']);
        });

        Schema::table('patients', fn(Blueprint $table) => $table->dropColumn('web_blocked_at'));
    }
};
