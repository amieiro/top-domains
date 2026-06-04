<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite index supporting the scanning query, which filters by batch_id
     * and is_wordpress and walks rows in id order (keyset pagination).
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->index(['batch_id', 'is_wordpress', 'id'], 'domains_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex('domains_scan_index');
        });
    }
};
