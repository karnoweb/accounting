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

        Schema::create($prefix . 'document_logs', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('document_id')->constrained($prefix . 'documents')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 20);
            $table->string('description', 255)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });

        if (Schema::hasTable($userTable)) {
            Schema::table($prefix . 'document_logs', function (Blueprint $table) use ($userTable) {
                $table->foreign('user_id')->references('id')->on($userTable)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.general.prefix', 'acc_') . 'document_logs');
    }
};
