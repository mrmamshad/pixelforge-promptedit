<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->string('pixelforge_id', 64)->nullable()->unique()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('size', 32)->default('1024x1024');
            $table->string('quality', 32)->default('medium');
            $table->json('reference_images')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key', 64)->nullable()->index();

            // Token Breakdown
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->integer('total_tokens')->nullable();

            // Unit Rates captured at generation creation (USD per 1M tokens)
            $table->decimal('rate_input_text', 12, 4)->nullable();
            $table->decimal('rate_input_image', 12, 4)->nullable();
            $table->decimal('rate_output', 12, 4)->nullable();
            $table->decimal('rate_reasoning', 12, 4)->nullable();

            // Accurate Money Accounting (USD stored with 9 decimal places)
            $table->decimal('estimated_cost_usd', 18, 9)->nullable();
            $table->decimal('provider_cost_usd', 18, 9)->nullable();
            $table->decimal('user_charged_usd', 18, 9)->nullable();
            $table->decimal('profit_usd', 18, 9)->nullable();
            $table->timestamp('billed_at')->nullable();

            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique()->index();
            $table->string('event_type', 64)->index();
            $table->integer('delivery_attempt')->default(1);
            $table->json('payload');
            $table->string('status', 32)->default('processed');
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index(); // topup, charge, refund
            $table->decimal('amount_usd', 18, 9);
            $table->decimal('balance_after_usd', 18, 9);
            $table->string('description');
            $table->string('reference_id', 64)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
    }
};
