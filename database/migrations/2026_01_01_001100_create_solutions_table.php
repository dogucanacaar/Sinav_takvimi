<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Her uretim ayri bir cozum kaydidir. Eski cozumler silinmez;
        // SolutionCompare ekrani iki kaydi yan yana koyabilsin diye saklanir.
        Schema::create('solutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('status')->default('queued'); // queued|running|completed|failed
            $table->json('params');                      // sicaklik, soguma, agirliklar, tohum
            $table->integer('penalty')->nullable();
            $table->integer('hard_violations')->nullable();
            $table->json('stats')->nullable();           // E1-E4 sayilari, yuk sapmasi, sure
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->timestamp('finished_at')->nullable();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solutions');
    }
};
