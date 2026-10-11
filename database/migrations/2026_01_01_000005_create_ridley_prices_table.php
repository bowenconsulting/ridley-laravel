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
            $table->string('ridley_price_id')->unique();
            $table->string('ridley_product_id')->nullable();
            $table->string('lookup_key')->nullable()->index();
            $table->string('group');
            $table->string('item_key');
            $table->char('currency', 3);
            $table->string('interval')->nullable();
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedBigInteger('unit_amount');
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['group', 'item_key', 'currency', 'interval', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('ridley.tables.prices', 'ridley_prices');
    }
};
