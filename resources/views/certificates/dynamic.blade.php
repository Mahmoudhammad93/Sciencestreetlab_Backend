@php
    /** @var array<string, mixed> $page */
    /** @var list<array<string, mixed>> $elements */
    $w = (float) ($page['width_mm'] ?? 297);
    $h = (float) ($page['height_mm'] ?? 210);
    $border = (string) ($page['border_color'] ?? '#2828a0');
    $borderW = (float) ($page['border_width_mm'] ?? 6);
    $bg = (string) ($page['background_color'] ?? '#FFFFFF');
    $innerW = max(0, $w - (2 * $borderW));
    $innerH = max(0, $h - (2 * $borderW));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: {{ $w }}mm {{ $h }}mm; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: {{ $w }}mm;
            height: {{ $h }}mm;
            overflow: hidden;
            font-family: DejaVu Sans, sans-serif;
            background: {{ $bg }};
        }
        .page {
            position: absolute;
            top: 0;
            left: 0;
            width: {{ $w }}mm;
            height: {{ $h }}mm;
            overflow: hidden;
            page-break-after: avoid;
            page-break-inside: avoid;
            page-break-before: avoid;
            background: {{ $bg }};
            @if(!empty($backgroundUrl))
            background-image: url('{{ $backgroundUrl }}');
            background-size: cover;
            background-position: center;
            @endif
        }
        .page-frame {
            position: absolute;
            top: 0;
            left: 0;
            width: {{ $innerW }}mm;
            height: {{ $innerH }}mm;
            border: {{ $borderW }}mm solid {{ $border }};
            pointer-events: none;
        }
        .el {
            position: absolute;
            overflow: hidden;
        }
        .el-text {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.25;
        }
        .el-line {
            border-top-style: solid;
            border-top-width: 0.4mm;
        }
        .sign-line {
            border-top: 0.35mm solid currentColor;
            margin: 2mm 0 1.5mm;
        }
        .sign-name { font-weight: 700; font-size: 10pt; }
        .sign-title { font-size: 8pt; }
        .sign-img { max-height: 14mm; max-width: 100%; display: block; margin: 0 auto 1mm; }
        .deco-signpost {
            width: 100%;
            height: 100%;
        }
        .deco-signpost .pole {
            position: absolute;
            left: 8mm;
            top: 2mm;
            width: 2.2mm;
            height: 30mm;
            background: currentColor;
        }
        .deco-signpost .blade {
            position: absolute;
            left: 2mm;
            top: 6mm;
            min-width: 42mm;
            height: 12mm;
            background: currentColor;
            color: #FCD500;
            clip-path: polygon(0 0, 88% 0, 100% 50%, 88% 100%, 0 100%);
            display: flex;
            align-items: center;
            padding: 0 4mm 0 3mm;
            font-size: 9pt;
            font-weight: 700;
        }
        .deco-ribbon {
            width: 100%;
            height: 100%;
            background: currentColor;
            clip-path: polygon(0 0, 100% 0, 100% 88%, 50% 100%, 0 88%);
            color: #FCD500;
            text-align: center;
            padding-top: 8mm;
            font-size: 8pt;
            font-weight: 700;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
        }
        .qr img { width: 100%; height: 100%; }
    </style>
</head>
<body>
<div class="page">
@if($borderW > 0)
    <div class="page-frame"></div>
@endif
@foreach($elements as $el)
    @php
        $type = $el['type'] ?? 'text';
        $x = (float) ($el['x'] ?? 0);
        $y = (float) ($el['y'] ?? 0);
        $ew = (float) ($el['width'] ?? 40);
        $eh = (float) ($el['height'] ?? 10);
        $color = (string) ($el['color'] ?? '#1a2a5c');
        $align = (string) ($el['text_align'] ?? 'center');
        $weight = (string) ($el['font_weight'] ?? '400');
        $size = (float) ($el['font_size'] ?? 12);
        $dir = (string) ($el['dir'] ?? 'ltr');
        $ls = (string) ($el['letter_spacing'] ?? 'normal');
        $pdfFamily = \App\Modules\Certification\Application\Support\CertificateFontRegistry::pdfFamily(
            (string) ($el['font_family'] ?? \App\Modules\Certification\Application\Support\CertificateFontRegistry::defaultFamily()),
            $weight
        );
    @endphp

    @php
        $rot = (float) ($el['rotation'] ?? 0);
        $op = $el['opacity'] ?? 1;
        $xf = $rot !== 0.0 ? 'transform:rotate('.$rot.'deg);' : '';
        $box = "left:{$x}mm;top:{$y}mm;width:{$ew}mm;height:{$eh}mm;opacity:{$op};{$xf}";
    @endphp
    @if($type === 'text')
        <div class="el el-text" style="{{ $box }}color:{{ $color }};text-align:{{ $align }};font-weight:normal;font-size:{{ $size }}pt;letter-spacing:{{ $ls }};direction:{{ $dir }};font-family:'{{ $pdfFamily }}', 'DejaVu Sans', sans-serif;line-height:{{ $el['line_height'] ?? '1.25' }};">
            {!! nl2br($el['resolved'] ?? '') !!}
        </div>
    @elseif($type === 'line')
        <div class="el el-line" style="{{ $box }}border-top-color:{{ $color }};height:{{ max($eh, 1) }}mm;"></div>
    @elseif($type === 'image' && !empty($el['image_url']))
        <div class="el" style="{{ $box }}">
            <img src="{{ $el['image_url'] }}" alt="" style="width:100%;height:100%;object-fit:{{ $el['object_fit'] ?? 'contain' }};">
        </div>
    @elseif($type === 'svg')
        <div class="el" style="{{ $box }}overflow:hidden;">
            @if(!empty($el['image_url']))
                <img src="{{ $el['image_url'] }}" alt="" style="width:100%;height:100%;object-fit:{{ $el['object_fit'] ?? 'fill' }};">
            @elseif(!empty($el['svg_markup']))
                {!! $el['svg_markup'] !!}
            @endif
        </div>
    @elseif($type === 'qr' && !empty($el['qr_data_uri']))
        <div class="el qr" style="{{ $box }}">
            <img src="{{ $el['qr_data_uri'] }}" alt="QR">
        </div>
    @elseif($type === 'signature_block')
        <div class="el" style="{{ $box }}color:{{ $color }};text-align:{{ $align }};">
            @if(!empty($el['signature_url']))
                <img class="sign-img" src="{{ $el['signature_url'] }}" alt="">
            @else
                <div style="height:10mm;"></div>
            @endif
            <div class="sign-line"></div>
            <div class="sign-name">{{ $el['signer_name'] ?? '' }}</div>
            <div class="sign-title">{{ $el['signer_title'] ?? '' }}</div>
        </div>
    @elseif($type === 'decoration')
        @if(($el['decoration'] ?? '') === 'ribbon')
            <div class="el deco-ribbon" style="{{ $box }}background:{{ $color }};color:{{ $el['accent_color'] ?? '#FCD500' }};">
                {{ $el['label'] ?? '' }}
            </div>
        @else
            <div class="el deco-signpost" style="{{ $box }}color:{{ $color }};">
                <div class="pole"></div>
                <div class="blade" style="color:{{ $el['accent_color'] ?? '#FCD500' }};">{{ $el['label'] ?? '' }}</div>
            </div>
        @endif
    @endif
@endforeach
</div>
</body>
</html>
