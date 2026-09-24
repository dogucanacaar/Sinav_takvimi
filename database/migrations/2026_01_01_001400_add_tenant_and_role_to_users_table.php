<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kullanıcı da bir kuruma aittir: yetki kontrolünden önce
            // hangi kurumun verisine baktığı belirlenir.
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');

            // admin | department_head | lecturer
            $table->string('role')->default('lecturer')->after('password');

            // Öğretim üyesi rolündeki kullanıcı, lecturers tablosundaki
            // kaydıyla eşleşir; "kendi görevlerim" ekranı buna dayanır.
            $table->unsignedBigInteger('lecturer_id')->nullable()->after('role');

            $table->index(['tenant_id', 'role']);
            $table->index('lecturer_id');
        });

        // Yabancı anahtar kısıtı ayrı eklenir: SQLite mevcut bir tabloya
        // ALTER TABLE ile kısıt ekleyemez ve testler SQLite üzerinde koşar.
        // Uygulamada tek gerçek veritabanı PostgreSQL olduğu için kısıt
        // orada kurulur, testlerde atlanır.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
                $table->foreign('lecturer_id')->references('id')->on('lecturers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'role', 'lecturer_id']);
        });
    }
};
