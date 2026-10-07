<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: 297mm 210mm; }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 297mm;
            height: 210mm;
            overflow: hidden;
            font-family: DejaVu Sans, sans-serif;
            background: #f8f9ff;
        }
        .certificate {
            position: absolute;
            top: 0;
            left: 0;
            width: 281mm;
            height: 194mm;
            border: 8mm solid #2828a0;
            padding: 12mm 16mm;
            text-align: center;
            overflow: hidden;
            page-break-after: avoid;
            page-break-inside: avoid;
        }
        .brand {
            color: #2828a0;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 40px;
        }
        .title {
            color: #fcd500;
            background: #2828a0;
            display: inline-block;
            padding: 8px 24px;
            font-size: 22px;
            margin-bottom: 30px;
        }
        .student {
            font-size: 32px;
            font-weight: bold;
            color: #2828a0;
            margin: 20px 0;
        }
        .course {
            font-size: 20px;
            color: #333;
            margin-bottom: 30px;
        }
        .meta {
            font-size: 12px;
            color: #666;
            margin-top: 40px;
        }
        .verify {
            position: absolute;
            bottom: 12mm;
            left: 12mm;
            font-size: 9px;
            color: #999;
            text-align: left;
            direction: ltr;
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="brand">Science Street Lab</div>
        <div class="subtitle">شارع العلوم — شهادة إتمام</div>
        <div class="title">Certificate of Completion</div>
        <p>يُشهد بأن</p>
        <div class="student">{{ $studentName }}</div>
        <p>قد أتم بنجاح دورة</p>
        <div class="course">{{ $courseTitle }}</div>
        <div class="meta">
            تاريخ الإصدار: {{ $issuedDate }}<br>
            رقم الشهادة: {{ $certificateNumber }}
        </div>
        <div class="verify">{{ $verificationUrl }}</div>
    </div>
</body>
</html>
