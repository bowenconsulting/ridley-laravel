<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('ridley_customer_id')->nullable()->index();
            $table->string('pm_brand')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->unsignedTinyInteger('pm_exp_month')->nullable();
            $table->unsignedSmallInteger('pm_exp_year')->nullable();
            $table->char('ridley_currency', 3)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
        });
    }
};
