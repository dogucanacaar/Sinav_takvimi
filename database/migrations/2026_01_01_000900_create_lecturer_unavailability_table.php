<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // K4: ogretim uyesinin musait olmadigi saat dilimleri.
        Schema::create('lecturer_unavailability', function (Blueprint $table) {
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();

            $table->primary(['lecturer_id', 'slot_id']);
            $table->index('slot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturer_unavailability');
    }
};
