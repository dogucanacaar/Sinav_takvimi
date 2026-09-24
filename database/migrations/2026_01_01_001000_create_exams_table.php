<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Her dersin tam olarak bir sinavi vardir (course_id unique).
        // student_count, enrollments'tan turetilip burada onbelleklenir:
        // motor her hamlede COUNT(*) atmamalidir.
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('student_count');
            $table->integer('duration_min')->default(60);
            $table->timestamps();

            $table->index(['tenant_id', 'student_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
