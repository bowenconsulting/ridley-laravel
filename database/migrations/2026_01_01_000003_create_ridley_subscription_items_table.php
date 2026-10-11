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
            $table->foreignId('subscription_id')->index();
            $table->string('ridley_id')->unique();
            $table->string('ridley_price');
            $table->string('ridley_product')->nullable();
            $table->integer('quantity')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('ridley.tables.subscription_items', 'ridley_subscription_items');
    }
};
