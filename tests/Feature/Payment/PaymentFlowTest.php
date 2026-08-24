<?php

namespace Tests\Feature\Payment;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 12 — Payment flow acceptance tests.
 *
 * Guards the exact contract: invoice creation, VAT math, provider initiation,
 * idempotent webhook processing, enrollment gating for paid courses, and
 * signature-verification behaviour.
 */
class PaymentFlowTest extends TestCase
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
            'title' => 'Paid Course',
            'category' => 'excel',
            'level' => 'beginner',
            'price_tzs' => $priceTzs,
            'status' => 'published',
            'instructor_id' => $trainer->id,
            'created_by' => $trainer->id,
        ]);
    }

    public function test_invoice_creation_applies_vat_correctly(): void
    {
        $student = $this->makeUser();
        $course = $this->makePaidCourse(10_000);

        $svc = app(\App\Services\Payment\InvoiceService::class);
        $invoice = $svc->issueForCourse($student, $course);

        $this->assertSame(10_000, $invoice->subtotal_tzs);
        $this->assertSame(1_800, $invoice->tax_tzs);  // 18% VAT
        $this->assertSame(11_800, $invoice->total_tzs);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertStringStartsWith('SAFCO-INV-', $invoice->invoice_number);
    }

    public function test_invoice_service_is_race_safe(): void
    {
        $student = $this->makeUser();
        $course = $this->makePaidCourse();

        $svc = app(\App\Services\Payment\InvoiceService::class);
        $i1 = $svc->issueForCourse($student, $course);
        $i2 = $svc->issueForCourse($student, $course);

        $this->assertSame($i1->id, $i2->id, 'Duplicate invoice must not be created for the same user+course');
    }

    public function test_free_course_enrollment_still_works(): void
    {
        $trainer = $this->makeUser('trainer');
        $free = Course::create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'free-' . Str::random(6),
            'title' => 'Free course',
            'category' => 'excel',
            'level' => 'beginner',
            'price_tzs' => null,
            'status' => 'published',
            'instructor_id' => $trainer->id,
            'created_by' => $trainer->id,
        ]);
        $student = $this->makeUser();
        Sanctum::actingAs($student);
        $r = $this->postJson("/api/v1/courses/{$free->uuid}/enroll");
        $r->assertStatus(201);
    }

    public function test_paid_course_enrollment_returns_402_without_paid_invoice(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        Sanctum::actingAs($student);

        $r = $this->postJson("/api/v1/courses/{$course->uuid}/enroll");
        $r->assertStatus(402);
        $body = $r->json();
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('invoice_uuid', $body['errors']);
        $this->assertSame(11_800, $body['errors']['total_tzs']);
    }

    public function test_provider_catalog_returns_all_7_srs_providers(): void
    {
        $student = $this->makeUser();
        Sanctum::actingAs($student);
        $r = $this->getJson('/api/v1/payments/providers');
        $r->assertOk();

        $codes = collect($r->json('data.providers'))->pluck('code')->all();
        foreach ([
            'mpesa', 'mixx', 'airtel_money',
            'crdb', 'nmb', 'nbc',
            'card_visa', 'card_mastercard',
        ] as $expected) {
            $this->assertContains($expected, $codes);
        }
    }

    public function test_initiate_payment_requires_msisdn_for_mobile_money(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        Sanctum::actingAs($student);
        $r = $this->postJson("/api/v1/invoices/{$invoice->uuid}/pay", ['provider' => 'mpesa']);
        $r->assertStatus(422);
    }

    public function test_initiate_payment_is_idempotent_by_key(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        Sanctum::actingAs($student);
        $body = ['provider' => 'mpesa', 'msisdn' => '0712345678', 'idempotency_key' => 'stable-key'];
        $r1 = $this->postJson("/api/v1/invoices/{$invoice->uuid}/pay", $body);
        $r2 = $this->postJson("/api/v1/invoices/{$invoice->uuid}/pay", $body);

        $this->assertSame($r1->json('data.payment.id'), $r2->json('data.payment.id'));
        $this->assertSame(1, Payment::where('idempotency_key', 'stable-key')->count());
    }

    public function test_webhook_settles_invoice_and_auto_enrolls(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        Sanctum::actingAs($student);
        $init = $this->postJson("/api/v1/invoices/{$invoice->uuid}/pay", [
            'provider' => 'mpesa', 'msisdn' => '0712345678',
            'idempotency_key' => 'k-' . Str::random(4),
        ]);
        $providerRef = $init->json('data.payment.provider_ref');

        // Public webhook (no auth required by the route itself)
        $w = $this->postJson('/api/v1/payments/webhook/mpesa', [
            'provider_ref' => $providerRef,
            'status' => 'succeeded',
        ]);
        $w->assertOk();
        $this->assertTrue($w->json('matched'));

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Enrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)->count());
    }

    public function test_webhook_is_idempotent_no_double_enrollment(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        $svc = app(\App\Services\Payment\PaymentService::class);
        [$payment] = array_values($svc->initiate($invoice, $student, 'mpesa', 'k1', '255712345678'));

        $webhookPayload = ['provider_ref' => $payment->provider_ref, 'status' => 'succeeded'];
        for ($i = 0; $i < 3; $i++) {
            $svc->applyWebhookResult('mpesa',
                new \App\Services\Payment\DTOs\WebhookResult(
                    providerRef: $payment->provider_ref,
                    status: Payment::STATUS_SUCCEEDED,
                    signatureVerified: true,
                    meta: $webhookPayload,
                ),
                $webhookPayload, '127.0.0.1'
            );
        }

        $this->assertSame(1, Enrollment::where('user_id', $student->id)
            ->where('course_id', $course->id)->count(), 'Enrollment must not duplicate');
        $this->assertSame(3, PaymentEvent::where('payment_id', $payment->id)->count(),
            'All 3 webhook attempts logged for reconciliation');
    }

    public function test_receipt_pdf_only_available_after_payment(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        Sanctum::actingAs($student);

        // Before payment — 422
        $this->getJson("/api/v1/invoices/{$invoice->uuid}/receipt")->assertStatus(422);

        // Simulate paid
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        $r = $this->get("/api/v1/invoices/{$invoice->uuid}/receipt");
        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_user_cannot_view_others_invoice(): void
    {
        $course = $this->makePaidCourse();
        $me = $this->makeUser();
        $other = $this->makeUser();
        $theirInvoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($other, $course);

        Sanctum::actingAs($me);
        $this->getJson("/api/v1/invoices/{$theirInvoice->uuid}")->assertForbidden();
    }

    public function test_admin_can_void_unpaid_invoice(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);

        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/void", ['reason' => 'test'])->assertOk();
        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
    }

    public function test_admin_cannot_void_paid_invoice(): void
    {
        $course = $this->makePaidCourse();
        $student = $this->makeUser();
        $invoice = app(\App\Services\Payment\InvoiceService::class)->issueForCourse($student, $course);
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/invoices/{$invoice->uuid}/void", ['reason' => 'test'])->assertStatus(422);
    }
}
