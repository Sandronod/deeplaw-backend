<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laws', function (Blueprint $table) {
            $table->id();
            $table->string('matsne_id', 64)->unique()->nullable();
            $table->text('title');
            $table->string('document_num', 64)->nullable();
            $table->string('category', 64)->nullable();   // 'კანონი', 'კოდექსი', etc.
            $table->string('status', 16)->default('active'); // active | repealed
            $table->date('adopted_at')->nullable();
            $table->text('source_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laws');
    }
};
