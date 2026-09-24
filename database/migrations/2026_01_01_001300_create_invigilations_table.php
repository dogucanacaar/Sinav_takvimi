<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invigilations', function (Blueprint $table) {
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();

            $table->primary(['solution_id', 'exam_id', 'lecturer_id']);
            $table->index(['solution_id', 'lecturer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invigilations');
    }
};
