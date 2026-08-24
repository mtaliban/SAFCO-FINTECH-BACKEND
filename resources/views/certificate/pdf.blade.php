<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate — {{ $cert->cert_number }}</title>
    <style>
        @page { size: A4 landscape; margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1e293b; }
        .cert {
            width: 100%; height: 100vh;
            padding: 60px 70px;
            box-sizing: border-box;
            position: relative;
            background: #fff;
            border: 12px solid #0f172a;
        }
        .cert:before {
            content: ''; position: absolute; top: 20px; left: 20px; right: 20px; bottom: 20px;
            border: 2px solid #f97316; pointer-events: none;
        }
        .header { text-align: center; }
        .brand {
            font-size: 14px; color: #f97316; letter-spacing: 8px; font-weight: bold;
            text-transform: uppercase;
        }
        .brand-name { font-size: 22px; color: #0f172a; margin-top: 4px; font-weight: bold; }
        .title {
            font-size: 44px; font-weight: bold; color: #0f172a;
            text-align: center; margin: 40px 0 8px 0; letter-spacing: 2px;
        }
        .subtitle { font-size: 14px; text-align: center; color: #64748b; letter-spacing: 4px; text-transform: uppercase; }
        .body { text-align: center; margin-top: 40px; font-size: 18px; color: #334155; line-height: 1.6; }
        .student {
            font-size: 42px; font-weight: bold; color: #0f172a;
            margin: 18px 0 20px 0;
            border-bottom: 2px solid #f97316;
            display: inline-block;
            padding: 0 40px 8px 40px;
        }
        .course { font-size: 24px; font-weight: bold; color: #0369a1; margin-top: 12px; }
        .footer {
            position: absolute; bottom: 60px; left: 70px; right: 70px;
            display: block;
        }
        .footer-row { width: 100%; }
        .footer-cell { display: inline-block; vertical-align: bottom; }
        .footer-left  { width: 45%; text-align: left; }
        .footer-right { width: 45%; text-align: right; }
        .footer-center { width: 10%; text-align: center; }
        .meta-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 4px; }
        .meta-value { font-size: 14px; color: #0f172a; font-weight: bold; }
        .qr { width: 110px; height: 110px; }
        .cert-number-strip {
            position: absolute; bottom: 20px; left: 20px; right: 20px;
            text-align: center; font-size: 10px; color: #94a3b8; letter-spacing: 4px;
        }
        .revoked-overlay {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-size: 120px; font-weight: bold;
            color: rgba(239, 68, 68, 0.35);
            border: 8px solid rgba(239, 68, 68, 0.35);
            padding: 10px 40px;
            letter-spacing: 12px;
            z-index: 100;
        }
    </style>
</head>
<body>
<div class="cert">
    @if ($cert->status === 'revoked')
        <div class="revoked-overlay">REVOKED</div>
    @endif

    <div class="header">
        <div class="brand">SAFCO FINTECH</div>
        <div class="brand-name">Learning Management System</div>
    </div>

    <div class="title">Certificate of Completion</div>
    <div class="subtitle">This is to certify that</div>

    <div class="body">
        <div class="student">{{ $cert->student_name_snapshot }}</div>
        <div>has successfully completed the course</div>
        <div class="course">{{ $cert->course_title_snapshot }}</div>
        @if ($cert->score_percentage !== null)
            <div style="margin-top: 12px; color: #64748b;">
                with a final score of <strong style="color: #0f172a;">{{ number_format($cert->score_percentage, 1) }}%</strong>
            </div>
        @endif
    </div>

    <div class="footer">
        <table class="footer-row" style="border-collapse: collapse; width: 100%;">
            <tr>
                <td class="footer-cell footer-left">
                    <div class="meta-label">Completion Date</div>
                    <div class="meta-value">{{ $cert->completion_date->format('F j, Y') }}</div>
                    <div class="meta-label" style="margin-top: 14px;">Issued On</div>
                    <div class="meta-value">{{ $cert->issued_at->format('F j, Y') }}</div>
                </td>
                <td class="footer-cell footer-center" style="text-align:center;">
                    {!! $qrSvg !!}
                </td>
                <td class="footer-cell footer-right">
                    <div class="meta-label">Certificate Number</div>
                    <div class="meta-value" style="font-family: monospace;">{{ $cert->cert_number }}</div>
                    <div class="meta-label" style="margin-top: 14px;">Verify At</div>
                    <div class="meta-value" style="font-size: 10px; word-break: break-all;">{{ $verifyUrl }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="cert-number-strip">
        SAFCO FINTECH LMS · Verify this certificate at {{ $verifyUrl }}
    </div>
</div>
</body>
</html>
