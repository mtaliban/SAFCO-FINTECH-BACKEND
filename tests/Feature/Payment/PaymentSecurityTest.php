<?php

namespace Tests\Feature\Payment;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payment\DTOs\WebhookResult;
use App\Services\Payment\PaymentService;
use App\Services\Payment\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 12 — SECURITY AUDIT test suite.
 *
 * Guards the audit fixes:
 *   - Webhook amount mismatch is rejected
 *   - Webhook currency mismatch is rejected
 *   - Late webhook for voided invoice is rejected (marks payment reversed)
 *   - HMAC signature verification passes with valid signature
 *   - HMAC signature verification fails with wrong signature
 *   - Refund lifecycle: full refund, partial refund, over-refund rejected
 *   - Fully-refunded course refund revokes enrollment (if not consumed)
 *   - Expire stale payments command works
 *   - Non-admin cannot refund
 */
class PaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        config(['payments.mock_mode' => true]);
    }

    private function makeUser(string $role = 'student', array $extra = []): User
    {
        $u = User::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'email' => 'u' . Str::random(6) . '@t.io',
            'password' => bcrypt('secret'),
            'email_verified_at' => now(),
            'status' => 'active',
        ], $extra));
        $u->assignRole($role);
        return $u;
    }

    private function makePaidCourse(int $priceTzs = 10_000): Course
    {
        $trainer = $this->makeUser('trainer');
        return Course::create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'c-' . Str::random(6),
            'title' => 'Course',
            'category' => 'excel',
            'level' => 'beginner',
            'price_tzs' => $priceTzs,
            'status' => 'published',
            'instructor_id' => $trainer->id,
            'created_by' => $trainer->id,
        ]);
    }

    private function paidInvoice(): array
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'k-' . Str::random(4), msisdn: '255712345678',
        ));
        $svc->applyWebhookResult('mpesa',
            new WebhookResult(
                providerRef: $payment->provider_ref,
                status: Payment::STATUS_SUCCEEDED,
                amountTzs: $invoice->total_tzs,
                currency: 'TZS',
                signatureVerified: true,
            ),
            ['provider_ref' => $payment->provider_ref, 'status' => 'succeeded'],
            '127.0.0.1',
        );
        return [$course, $student, $invoice->fresh(), $payment->fresh()];
    }

    // ── SECURITY: amount / currency tampering ─────────────────────

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $course = $this->makePaidCourse(50_000);
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'k1', msisdn: '255712345678',
        ));

        // Attacker sends a webhook claiming success for the huge invoice but for only 100 TZS.
        $result = $svc->applyWebhookResult('mpesa',
            new WebhookResult(
                providerRef: $payment->provider_ref,
                status: Payment::STATUS_SUCCEEDED,
                amountTzs: 100,        // WRONG — expected 59,000 (50k + VAT)
                currency: 'TZS',
                signatureVerified: true,
            ),
            ['provider_ref' => $payment->provider_ref, 'amount_tzs' => 100],
            '127.0.0.1',
        );

        $this->assertSame(Payment::STATUS_FAILED, $result->status);
        $this->assertSame('amount_mismatch', $result->failure_code);
        $this->assertFalse($invoice->fresh()->isPaid());
        $this->assertSame(0, Enrollment::where('user_id', $student->id)->count());
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'k2', msisdn: '255712345678',
        ));

        $result = $svc->applyWebhookResult('mpesa',
            new WebhookResult(
                providerRef: $payment->provider_ref,
                status: Payment::STATUS_SUCCEEDED,
                amountTzs: $invoice->total_tzs,
                currency: 'USD',            // wrong currency
                signatureVerified: true,
            ),
            ['provider_ref' => $payment->provider_ref],
            '127.0.0.1',
        );

        $this->assertSame(Payment::STATUS_FAILED, $result->status);
        $this->assertSame('currency_mismatch', $result->failure_code);
    }

    public function test_late_webhook_for_voided_invoice_marks_payment_reversed(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'k3', msisdn: '255712345678',
        ));

        // Admin voids invoice before webhook arrives
        app(\App\Services\Payment\InvoiceService::class)->void($invoice, 'test');

        // Now the delayed webhook arrives
        $result = $svc->applyWebhookResult('mpesa',
            new WebhookResult(
                providerRef: $payment->provider_ref,
                status: Payment::STATUS_SUCCEEDED,
                amountTzs: $invoice->total_tzs,
                currency: 'TZS',
                signatureVerified: true,
            ),
            ['provider_ref' => $payment->provider_ref],
            '127.0.0.1',
        );

        $this->assertSame(Payment::STATUS_REVERSED, $result->status);
        $this->assertSame('invoice_finalized', $result->failure_code);
        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame(0, Enrollment::where('user_id', $student->id)->count());
    }

    // ── HMAC signature verification ───────────────────────────────

    public function test_webhook_signature_verification_passes_when_correctly_signed(): void
    {
        config(['payments.mock_mode' => false]);
        config(['payments.providers.mpesa.webhook_secret' => 'test-secret-xyz']);

        $rawBody = json_encode(['provider_ref' => 'ref-abc', 'status' => 'succeeded']);
        $sig = hash_hmac('sha256', $rawBody, 'test-secret-xyz');

        $provider = new \App\Services\Payment\Providers\MpesaProvider();
        $result = $provider->handleWebhook(
            json_decode($rawBody, true),
            ['x-safco-signature' => [$sig]],
            $rawBody,
        );

        $this->assertTrue($result->signatureVerified);
    }

    public function test_webhook_signature_verification_fails_with_wrong_signature(): void
    {
        config(['payments.mock_mode' => false]);
        config(['payments.providers.mpesa.webhook_secret' => 'test-secret-xyz']);

        $rawBody = json_encode(['provider_ref' => 'ref-def', 'status' => 'succeeded']);

        $provider = new \App\Services\Payment\Providers\MpesaProvider();
        $result = $provider->handleWebhook(
            json_decode($rawBody, true),
            ['x-safco-signature' => ['garbage-signature']],
            $rawBody,
        );

        $this->assertFalse($result->signatureVerified);
    }

    public function test_webhook_signature_verification_fails_with_no_signature_header(): void
    {
        config(['payments.mock_mode' => false]);
        config(['payments.providers.mpesa.webhook_secret' => 'test-secret-xyz']);

        $provider = new \App\Services\Payment\Providers\MpesaProvider();
        $result = $provider->handleWebhook(
            ['provider_ref' => 'ref-ghi', 'status' => 'succeeded'],
            [],
            '{}',
        );

        $this->assertFalse($result->signatureVerified);
    }

    // ── Refund lifecycle ──────────────────────────────────────────

    public function test_refund_full_amount_marks_invoice_refunded_and_revokes_enrollment(): void
    {
        [$course, $student, $invoice, $payment] = $this->paidInvoice();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(1, Enrollment::where('user_id', $student->id)->count());

        $admin = $this->makeUser('system_admin');
        $refund = app(RefundService::class)->issue($payment, $admin, $payment->amount_tzs, 'Cancelled');

        $this->assertSame(Refund::STATUS_SUCCEEDED, $refund->status);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        // Enrollment was not consumed (progress=0), so it's revoked
        $this->assertSame(0, Enrollment::where('user_id', $student->id)->count());
    }

    public function test_refund_partial_amount_marks_invoice_partially_refunded(): void
    {
        [$course, $student, $invoice, $payment] = $this->paidInvoice();
        $admin = $this->makeUser('system_admin');

        $refund = app(RefundService::class)->issue($payment, $admin, 1000, 'Partial');

        $this->assertSame(Refund::STATUS_SUCCEEDED, $refund->status);
        $this->assertSame(Invoice::STATUS_PARTIALLY_REFUNDED, $invoice->fresh()->status);
        // Partial refund does not revoke enrollment
        $this->assertSame(1, Enrollment::where('user_id', $student->id)->count());
    }

    public function test_refund_cannot_exceed_original_amount(): void
    {
        [, , , $payment] = $this->paidInvoice();
        $admin = $this->makeUser('system_admin');

        $this->expectException(\DomainException::class);
        app(RefundService::class)->issue($payment, $admin, $payment->amount_tzs + 1, 'Over');
    }

    public function test_multiple_partial_refunds_cannot_exceed_total(): void
    {
        [, , , $payment] = $this->paidInvoice();
        $admin = $this->makeUser('system_admin');

        app(RefundService::class)->issue($payment, $admin, 5_000, 'part 1');
        app(RefundService::class)->issue($payment, $admin, 5_000, 'part 2');

        // Total refunded so far: 10,000. Payment was 11,800. Remaining: 1,800.
        // Attempting another 2,000 must fail.
        $this->expectException(\DomainException::class);
        app(RefundService::class)->issue($payment, $admin, 2_000, 'part 3');
    }

    public function test_only_admin_can_refund_via_http(): void
    {
        [, , , $payment] = $this->paidInvoice();

        $nonAdmin = $this->makeUser('student');
        Sanctum::actingAs($nonAdmin);
        $this->postJson("/api/v1/payments/{$payment->uuid}/refund", [
            'amount_tzs' => 1000, 'reason' => 'try',
        ])->assertForbidden();
    }

    public function test_admin_can_refund_via_http(): void
    {
        [, , , $payment] = $this->paidInvoice();

        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);
        $r = $this->postJson("/api/v1/payments/{$payment->uuid}/refund", [
            'amount_tzs' => 1000, 'reason' => 'partial refund',
        ]);
        $r->assertStatus(201);
        $this->assertSame(1, Refund::count());
    }

    // ── Stale-payment cleanup ─────────────────────────────────────

    public function test_expire_stale_payments_command_transitions_pending_to_expired(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'stale', msisdn: '255712345678',
        ));
        // Age the payment past expiration
        $payment->update(['expires_at' => now()->subMinutes(30)]);

        $this->artisan('payments:expire-stale')
            ->assertExitCode(0)
            ->expectsOutputToContain('Expired 1');

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->fresh()->status);
    }

    public function test_expire_stale_does_not_touch_still_pending(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        $svc = app(PaymentService::class);
        [$payment] = array_values($svc->initiate(
            invoice: $invoice, payer: $student, providerCode: 'mpesa',
            idempotencyKey: 'fresh', msisdn: '255712345678',
        ));
        // Still within its window
        $payment->update(['expires_at' => now()->addMinutes(10)]);

        $this->artisan('payments:expire-stale')->assertExitCode(0);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    // ── Course serialization exposes price ────────────────────────

    public function test_course_api_response_exposes_price_and_is_free(): void
    {
        $paid = $this->makePaidCourse(15_000);
        $student = $this->makeUser();
        Sanctum::actingAs($student);

        $r = $this->getJson("/api/v1/courses/{$paid->uuid}");
        $r->assertOk();

        $this->assertSame(15_000, $r->json('data.price_tzs'));
        $this->assertFalse($r->json('data.is_free'));
    }
}
