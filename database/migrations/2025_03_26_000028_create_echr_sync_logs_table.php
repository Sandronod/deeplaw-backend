<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgvector';

    public function up(): void
    {
        Schema::create('echr_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->default('hudoc');
            $table->string('query_type', 50)->nullable();  // "topic", "application_number", "bulk", "on_demand"
            $table->text('query_value')->nullable();
            $table->unsignedInteger('cases_fetched')->default(0);
            $table->unsignedInteger('cases_new')->default(0);
            $table->string('status', 20)->default('success'); // success | error | partial
            $table->text('error_message')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('synced_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echr_sync_logs');
    }
};
