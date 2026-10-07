<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificate Verification — Science Street Lab</title>
    <style>
        :root {
            --ink: #1a2a5c;
            --accent: #fcd500;
            --muted: #64748b;
            --ok: #15803d;
            --bad: #b91c1c;
            --bg: #f1f5f9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, DejaVu Sans, sans-serif;
            background:
                radial-gradient(circle at 10% 10%, rgba(252, 213, 0, 0.18), transparent 40%),
                radial-gradient(circle at 90% 0%, rgba(91, 200, 225, 0.2), transparent 35%),
                var(--bg);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: min(520px, 100%);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px 24px;
            box-shadow: 0 10px 30px rgba(26, 42, 92, 0.08);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 1.4rem;
        }
        .brand {
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 18px;
        }
        .status {
            display: inline-block;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 999px;
            margin: 8px 0 18px;
            font-size: 0.9rem;
        }
        .status.ok { background: #dcfce7; color: var(--ok); }
        .status.bad { background: #fee2e2; color: var(--bad); }
        dl { margin: 0; }
        dt {
            color: var(--muted);
            font-size: 0.8rem;
            margin-top: 12px;
        }
        dd {
            margin: 2px 0 0;
            font-size: 1.05rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">Science Street Lab</div>
    <h1>Certificate verification</h1>

    @if($valid)
        <div class="status ok">Valid certificate</div>
        <dl>
            <dt>Student</dt>
            <dd>{{ $certificate['student_name'] }}</dd>
            <dt>Course</dt>
            <dd>{{ $certificate['course_title'] }}</dd>
            <dt>Certificate number</dt>
            <dd>{{ $certificate['certificate_number'] }}</dd>
            <dt>Issue date</dt>
            <dd>{{ $certificate['issued_at'] }}</dd>
            <dt>Completion date</dt>
            <dd>{{ $certificate['completion_date'] }}</dd>
        </dl>
    @else
        <div class="status bad">Certificate not found</div>
        <p style="color:var(--muted);margin:0;">This verification code is invalid or no longer available.</p>
    @endif
</main>
</body>
</html>
