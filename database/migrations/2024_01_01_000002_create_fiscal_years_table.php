<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_current')->default(false);
            $table->boolean('opening_done')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['start_date', 'end_date']);
            $table->index('status');
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
