<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // K5: bir cozumde bir sinav tam olarak bir kez yer alir.
            $table->primary(['solution_id', 'exam_id']);
            // K2: ayni saatte bir dersliğe tek sinav. Veritabani duzeyinde garanti.
            $table->unique(['solution_id', 'slot_id', 'room_id']);
            $table->index(['solution_id', 'slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
