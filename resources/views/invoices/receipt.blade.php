<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 40px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; margin: 0; }
        .brand { color: #f97316; font-size: 28px; font-weight: 900; }
        .brand-sub { color: #64748b; font-size: 10px; }
        .header { border-bottom: 3px solid #10b981; padding-bottom: 12px; margin-bottom: 24px; }
        .col-half { width: 48%; display: inline-block; vertical-align: top; }
        .col-half-right { float: right; text-align: right; }
        .doc-title { font-size: 22px; font-weight: 900; color: #065f46; }
        .doc-number { font-family: monospace; color: #f97316; font-weight: bold; font-size: 14px; }
        .paid-stamp { border: 3px solid #10b981; color: #10b981; padding: 12px 20px; display: inline-block; font-size: 24px; font-weight: 900; letter-spacing: 3px; transform: rotate(-6deg); border-radius: 8px; }
        .label { font-size: 9px; color: #64748b; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; }
        .value { font-size: 12px; color: #1e293b; font-weight: 600; }
        table.summary { width: 100%; border-collapse: collapse; margin: 24px 0; }
        table.summary td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        table.summary .label { padding: 8px; color: #64748b; }
        .amount-big { font-size: 28px; font-weight: 900; color: #065f46; font-family: monospace; }
        .footer { position: fixed; bottom: 20px; left: 40px; right: 40px; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    </style>
</head>
<body>

<div class="header">
    <div class="col-half">
        <div class="brand">SAFCO</div>
        <div class="brand-sub">FINTECH LIMITED</div>
    </div>
    <div class="col-half col-half-right">
        <div class="doc-title">OFFICIAL RECEIPT</div>
        <div class="doc-number">{{ $invoice->invoice_number }}</div>
    </div>
</div>

<div style="text-align: center; margin: 24px 0;">
    <div class="paid-stamp">PAID</div>
</div>

<div style="text-align:center; margin: 12px 0 32px;">
    <div class="label">Amount Received</div>
    <div class="amount-big">TZS {{ number_format($invoice->total_tzs) }}</div>
    <div style="color:#64748b; font-size: 11px;">
        {{ $invoice->paid_at?->format('d M Y, H:i') }}
    </div>
</div>

<table class="summary">
    <tr>
        <td class="label">Received From</td>
        <td class="value">
            {{ $invoice->billedUser->profile?->full_name ?? $invoice->billedUser->email }}
            @if($invoice->billedOrg) — {{ $invoice->billedOrg->name }} @endif
        </td>
    </tr>
    <tr>
        <td class="label">Description</td>
        <td class="value">{{ $invoice->description }}</td>
    </tr>
    <tr>
        <td class="label">Payment Method</td>
        <td class="value">
            @if($payment)
                {{ strtoupper(str_replace('_', ' ', $payment->provider)) }}
                @if($payment->msisdn) · {{ $payment->msisdn }} @endif
                @if($payment->card_last4) · **** {{ $payment->card_last4 }} @endif
            @else — @endif
        </td>
    </tr>
    <tr>
        <td class="label">Reference</td>
        <td class="value">{{ $payment?->provider_ref ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Subtotal</td>
        <td class="value">TZS {{ number_format($invoice->subtotal_tzs) }}</td>
    </tr>
    <tr>
        <td class="label">VAT ({{ $vatPct }}%)</td>
        <td class="value">TZS {{ number_format($invoice->tax_tzs) }}</td>
    </tr>
</table>

<div class="footer">
    Issued by {{ $issuer['name'] }} · TIN {{ $issuer['tin'] }}<br>
    {{ $issuer['address'] }} · {{ $issuer['email'] }} · {{ $issuer['phone'] }}
</div>

</body>
</html>
