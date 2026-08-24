<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 12 — Payment Management foundation.
 *
 *   invoices        – canonical bill (per course, per subscription, etc.)
 *   payments        – provider-specific payment attempts against an invoice
 *   payment_events  – append-only audit log of every callback/webhook
 *   refunds         – reversal records
 *
 * Money principles applied:
 *  - All monetary columns are UNSIGNED BIGINT in TZS whole shillings (no floats).
 *  - Idempotency: payments.idempotency_key is UNIQUE. Webhook retries can hit
 *    the callback endpoint 3+ times; we accept only the first.
 *  - Reconciliation: payment_events keeps raw provider payload forever.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Courses get a price ──────────────────────────────────
        Schema::table('courses', function (Blueprint $t) {
            $t->unsignedBigInteger('price_tzs')->nullable()->after('duration_hours');
            $t->index('price_tzs');
        });

        // ── invoices ─────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            // Human-readable, sequential invoice number: SAFCO-INV-2026-00001
            $t->string('invoice_number', 32)->unique();

            // Who is billed (student or corporate — polymorphic through Model)
            $t->foreignId('billed_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('billed_org_id')->nullable()->constrained('organizations')->nullOnDelete();

            // What is being paid for (polymorphic: course, subscription, etc.)
            $t->string('subject_type');           // e.g. App\Models\Course
            $t->unsignedBigInteger('subject_id'); // FK-ish
            $t->index(['subject_type', 'subject_id']);

            // Money — all in TZS whole shillings
            $t->unsignedBigInteger('subtotal_tzs');   // pre-tax
            $t->unsignedBigInteger('tax_tzs');        // VAT (18% in TZ)
            $t->unsignedBigInteger('total_tzs');      // subtotal + tax
            $t->string('currency', 3)->default('TZS');

            // Status lifecycle: draft → issued → paid | void | refunded
            $t->enum('status', ['draft', 'issued', 'paid', 'void', 'refunded', 'partially_refunded'])
              ->default('issued')->index();

            $t->string('description')->nullable();
            $t->json('line_items')->nullable(); // e.g. [{name, qty, unit_tzs, total_tzs}]
            $t->json('meta')->nullable();

            $t->timestamp('issued_at')->useCurrent();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('voided_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['billed_user_id', 'status']);
            $t->index(['billed_org_id', 'status']);
            $t->index('issued_at');
        });

        // ── payments (attempts) ──────────────────────────────────
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Provider code (mpesa, mixx, airtel, crdb, nmb, nbc, card_visa, card_mc)
            $t->string('provider', 32)->index();

            // Provider-side reference (STK push id, transaction id, etc.)
            $t->string('provider_ref', 128)->nullable()->index();

            // Client-side identifier for retries — MUST be unique.
            // Prevents duplicate payments when browser resends / webhook double-fires.
            $t->string('idempotency_key', 64)->unique();

            // Payer instrument (partial masking is caller's responsibility)
            $t->string('msisdn', 20)->nullable();       // 2557XXXXXXXX
            $t->string('card_last4', 4)->nullable();
            $t->string('card_brand', 16)->nullable();
            $t->string('bank_account_hash', 64)->nullable(); // hash, never raw acct

            $t->unsignedBigInteger('amount_tzs');
            $t->string('currency', 3)->default('TZS');

            // pending → succeeded | failed | cancelled | expired | reversed
            $t->enum('status', ['pending', 'succeeded', 'failed', 'cancelled', 'expired', 'reversed'])
              ->default('pending')->index();

            $t->string('failure_code', 64)->nullable();
            $t->text('failure_message')->nullable();

            $t->timestamp('initiated_at')->useCurrent();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('expires_at')->nullable();

            $t->json('meta')->nullable(); // provider-specific hints (checkout_url, stk_push_id, ...)

            $t->timestamps();

            $t->index(['invoice_id', 'status']);
            $t->index(['user_id', 'status']);
            $t->index(['provider', 'status']);
        });

        // ── payment_events (append-only audit log for reconciliation) ─
        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $t->string('provider', 32);

            // 'callback', 'webhook', 'status_query', 'refund_request'
            $t->string('event_type', 32);

            // Store the raw provider payload for reconciliation / audit
            $t->json('payload');

            // Signature verification result (true when provider signed the payload)
            $t->boolean('signature_verified')->default(false);

            $t->ipAddress('source_ip')->nullable();
            $t->timestamps();

            $t->index(['payment_id', 'event_type']);
            $t->index('created_at');
        });

        // ── refunds ──────────────────────────────────────────────
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            $t->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $t->unsignedBigInteger('amount_tzs');
            $t->string('reason', 255);

            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->string('provider_ref', 128)->nullable();

            $t->enum('status', ['pending', 'succeeded', 'failed'])
              ->default('pending')->index();

            $t->timestamp('requested_at')->useCurrent();
            $t->timestamp('completed_at')->nullable();

            $t->text('failure_message')->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::table('courses', function (Blueprint $t) {
            $t->dropIndex(['price_tzs']);
            $t->dropColumn('price_tzs');
        });
    }
};
