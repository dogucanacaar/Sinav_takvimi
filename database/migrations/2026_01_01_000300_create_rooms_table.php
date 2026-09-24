<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('capacity');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'capacity']);
        });

        // Kapasite her zaman pozitif olmali. Kontrol kisiti veritabani
        // duzeyinde tutulur; uygulamadaki dogrulama atlanabilir, bu atlanamaz.
        // (SQLite'ta ALTER TABLE ... ADD CONSTRAINT desteklenmedigi icin
        // sadece PostgreSQL'de eklenir; testler SQLite uzerinde kosuyor.)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_positive CHECK (capacity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
