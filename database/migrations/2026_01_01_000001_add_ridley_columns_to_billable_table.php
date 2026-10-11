<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('ridley_customer_id')->nullable()->index();
            $table->string('pm_brand')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->unsignedTinyInteger('pm_exp_month')->nullable();
            $table->unsignedSmallInteger('pm_exp_year')->nullable();
            $table->char('ridley_currency', 3)->nullable();
        });

        if (! Schema::hasColumn($this->table(), 'trial_ends_at')) {
            Schema::table($this->table(), function (Blueprint $table) {
                $table->timestamp('trial_ends_at')->nullable();
            });
        }
    }

    /**
     * `trial_ends_at` is left in place: it may have existed before this migration.
     */
    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['ridley_customer_id']);
            $table->dropColumn(['ridley_customer_id', 'pm_brand', 'pm_last_four', 'pm_exp_month', 'pm_exp_year', 'ridley_currency']);
        });
    }

    protected function table(): string
    {
        return (string) config('ridley.tables.billable', 'users');
    }
};
