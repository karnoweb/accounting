<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('accounting.general.prefix', 'acc_');

        Schema::create($prefix . 'document_items', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('document_id')->constrained($prefix . 'documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained($prefix . 'cost_centers')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->tinyInteger('sign');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.general.prefix', 'acc_') . 'document_items');
    }
};
