<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            background: #f8f9ff;
        }
        .certificate {
            width: 100%;
            height: 100%;
            border: 8px solid #2828a0;
            box-sizing: border-box;
            padding: 40px 60px;
            text-align: center;
            position: relative;
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
            bottom: 30px;
            left: 40px;
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
