<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');
        $userTable = config('accounting.user.table', 'users');
        $userForeignKey = config('accounting.user.foreign_key', 'user_id');

        Schema::create($prefix . 'documents', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained($prefix . 'fiscal_years')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained($prefix . 'branches')->nullOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('reference', 50)->nullable();
            $table->date('date');
            $table->string('type', 50);
            $table->string('status', 20)->default('draft');
            $table->string('description', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['fiscal_year_id', 'number']);
            $table->index('date');
            $table->index('type');
            $table->index('status');
            $table->index(['source_type', 'source_id']);
        });

        if (Schema::hasTable($userTable)) {
            Schema::table($prefix . 'documents', function (Blueprint $table) use ($userTable) {
                $table->foreign('created_by')->references('id')->on($userTable)->nullOnDelete();
                $table->foreign('approved_by')->references('id')->on($userTable)->nullOnDelete();
                $table->foreign('posted_by')->references('id')->on($userTable)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.general.prefix', 'acc_') . 'documents');
    }
};
