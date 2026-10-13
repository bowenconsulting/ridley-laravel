<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('ridley_id')->nullable()->unique();
            $table->char('currency', 3)->nullable();
            $table->string('pm_brand')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->unsignedTinyInteger('pm_exp_month')->nullable();
            $table->unsignedSmallInteger('pm_exp_year')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('ridley.tables.customers', 'ridley_customers');
    }
};
