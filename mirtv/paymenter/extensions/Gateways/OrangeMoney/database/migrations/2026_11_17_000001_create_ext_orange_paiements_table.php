<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ext_orange_paiements')) {
            return;
        }
        Schema::create('ext_orange_paiements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('order_id', 64)->unique();
            $table->string('pay_token', 255)->nullable();
            $table->string('notif_token', 255)->nullable()->index();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8);
            $table->string('mode', 16);
            $table->string('status', 16)->default('INITIATED')->index();
            $table->string('sim_status', 16)->nullable();
            $table->string('txnid', 64)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_orange_paiements');
    }
};
