<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saat dilimi: 12.06.2026 09:00 gibi.
        // index_in_day, "art arda sinav" (E2) kuralini bulabilmek icin tutulur.
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->smallInteger('index_in_day');
            $table->time('starts_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'day', 'index_in_day']);
            $table->index(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
