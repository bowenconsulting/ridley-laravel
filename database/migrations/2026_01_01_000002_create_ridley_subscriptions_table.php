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
            $table->string('type');
            $table->string('ridley_id')->unique();
            $table->string('ridley_status');
            $table->string('ridley_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('interval')->nullable();
            $table->unsignedSmallInteger('interval_count')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'ridley_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('ridley.tables.subscriptions', 'ridley_subscriptions');
    }
};
