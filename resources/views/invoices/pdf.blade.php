<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 40px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; margin: 0; }
        .brand { color: #f97316; font-size: 28px; font-weight: 900; letter-spacing: -1px; }
        .brand-sub { color: #64748b; font-size: 10px; }
        .header { border-bottom: 3px solid #f97316; padding-bottom: 12px; margin-bottom: 24px; }
        .row { width: 100%; }
        .col-half { width: 48%; display: inline-block; vertical-align: top; }
        .col-half-right { float: right; text-align: right; }
        .label { font-size: 9px; color: #64748b; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; }
        .value { font-size: 12px; color: #1e293b; font-weight: 600; }
        .doc-title { font-size: 22px; font-weight: 900; color: #1e3a8a; }
        .doc-number { font-family: monospace; color: #f97316; font-weight: bold; font-size: 14px; }
        .status-pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-issued { background: #fef3c7; color: #92400e; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-void { background: #fee2e2; color: #991b1b; }
        table.items { width: 100%; border-collapse: collapse; margin: 24px 0; }
        table.items thead th { background: #f1f5f9; color: #334155; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
        table.items tbody td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
        table.items .amount { text-align: right; font-family: monospace; font-weight: bold; }
        .totals { float: right; width: 40%; margin-top: 12px; }
        .totals td { padding: 5px 8px; font-size: 11px; }
        .totals .label-td { text-align: right; color: #64748b; }
        .totals .value-td { text-align: right; font-family: monospace; font-weight: bold; }
        .totals .grand td { font-size: 14px; font-weight: 900; color: #1e3a8a; border-top: 2px solid #1e3a8a; padding-top: 8px; }
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
        <div class="doc-title">TAX INVOICE</div>
        <div class="doc-number">{{ $invoice->invoice_number }}</div>
        <div style="margin-top:6px;">
            @if($invoice->status === 'paid')
                <span class="status-pill status-paid">Paid</span>
            @elseif($invoice->status === 'void')
                <span class="status-pill status-void">Void</span>
            @else
                <span class="status-pill status-issued">Issued</span>
            @endif
        </div>
    </div>
</div>

<div class="row" style="margin-bottom: 24px;">
    <div class="col-half">
        <div class="label">From</div>
        <div class="value">{{ $issuer['name'] }}</div>
        <div>{{ $issuer['address'] }}</div>
        <div>Phone: {{ $issuer['phone'] }}</div>
        <div>Email: {{ $issuer['email'] }}</div>
        <div>TIN: {{ $issuer['tin'] }}</div>
    </div>
    <div class="col-half col-half-right">
        <div class="label">Bill To</div>
        @if($invoice->billedOrg)
            <div class="value">{{ $invoice->billedOrg->name }}</div>
            @if($invoice->billedOrg->address){{ $invoice->billedOrg->address }}<br>@endif
            @if($invoice->billedOrg->tax_id)TIN: {{ $invoice->billedOrg->tax_id }}<br>@endif
        @else
            <div class="value">{{ $invoice->billedUser->profile?->full_name ?? $invoice->billedUser->email }}</div>
        @endif
        <div>{{ $invoice->billedUser->email }}</div>
    </div>
</div>

<div class="row" style="margin-bottom: 8px;">
    <div class="col-half">
        <div class="label">Issue Date</div>
        <div class="value">{{ $invoice->issued_at?->format('d M Y') }}</div>
    </div>
    <div class="col-half col-half-right">
        <div class="label">Due Date</div>
        <div class="value">{{ $invoice->due_at?->format('d M Y') ?? '—' }}</div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width:60%">Description</th>
            <th style="width:10%; text-align:center;">Qty</th>
            <th style="width:15%; text-align:right;">Unit (TZS)</th>
            <th style="width:15%; text-align:right;">Total (TZS)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->line_items ?? [] as $item)
        <tr>
            <td>{{ $item['name'] ?? '' }}</td>
            <td style="text-align:center;">{{ $item['qty'] ?? 1 }}</td>
            <td class="amount">{{ number_format($item['unit_tzs'] ?? 0) }}</td>
            <td class="amount">{{ number_format($item['total_tzs'] ?? 0) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label-td">Subtotal</td>
        <td class="value-td">TZS {{ number_format($invoice->subtotal_tzs) }}</td>
    </tr>
    <tr>
        <td class="label-td">VAT ({{ $vatPct }}%)</td>
        <td class="value-td">TZS {{ number_format($invoice->tax_tzs) }}</td>
    </tr>
    <tr class="grand">
        <td>TOTAL</td>
        <td>TZS {{ number_format($invoice->total_tzs) }}</td>
    </tr>
</table>

<div style="clear:both;"></div>

<div class="footer">
    Thank you for your business. Questions? Contact {{ $issuer['email'] }} · {{ $issuer['phone'] }}<br>
    This is a computer-generated document. No signature required.
</div>

</body>
</html>
