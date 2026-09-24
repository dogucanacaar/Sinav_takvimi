<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DIKKAT: courses tablosu lecturer_id ile buraya referans verdigi icin
        // bu goc courses'tan once calismalidir.
        Schema::create('lecturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('department')->nullable();
            // Gecmis donem yuku: gozetmen dagitiminda adaleti saglamak icin
            // baslangic yuku olarak kullanilir.
            $table->integer('past_duty_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturers');
    }
};
