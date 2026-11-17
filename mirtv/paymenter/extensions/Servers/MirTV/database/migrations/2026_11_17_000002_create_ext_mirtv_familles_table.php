<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_mirtv_familles')) {
            return;
        }
        Schema::create('ext_mirtv_familles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedInteger('numero')->unique();
            $table->string('jeton_paiement', 64)->unique();
            $table->string('code_activation', 16)->nullable()->index();
            $table->timestamp('code_expire_at')->nullable();
            $table->text('profils')->nullable(); // chiffré (noms, identifiants, mots de passe)
            $table->unsignedTinyInteger('ecrans')->default(2);
            $table->string('statut', 16)->default('creation');
            $table->timestamp('resiliee_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_mirtv_familles');
    }
};
