<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Date de la dernière relance WhatsApp envoyée depuis le guichet
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ext_mirtv_familles', 'relance_at')) {
            return;
        }
        Schema::table('ext_mirtv_familles', function (Blueprint $table) {
            $table->timestamp('relance_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ext_mirtv_familles', 'relance_at')) {
            Schema::table('ext_mirtv_familles', fn (Blueprint $t) => $t->dropColumn('relance_at'));
        }
    }
};
