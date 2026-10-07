@php
    /** @var array<string, mixed> $page */
    /** @var list<array<string, mixed>> $elements */
    $embed = ! empty($embed);
    $w = (float) ($page['width_mm'] ?? 297);
    $h = (float) ($page['height_mm'] ?? 210);
    $border = (string) ($page['border_color'] ?? '#2828a0');
    $borderW = (float) ($page['border_width_mm'] ?? 6);
    $bg = (string) ($page['background_color'] ?? '#FFFFFF');
    $innerW = max(0, $w - (2 * $borderW));
    $innerH = max(0, $h - (2 * $borderW));
@endphp
@unless($embed)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
@endunless
    <style>
        @unless($embed)
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
        @endunless
        .ssl-cert-root, .ssl-cert-root * { box-sizing: border-box; }
        .ssl-cert-root {
            position: relative;
            width: {{ $embed ? '100%' : $w.'mm' }};
            height: {{ $embed ? '100%' : $h.'mm' }};
            overflow: hidden;
            font-family: 'DejaVu Sans', Cairo, Tahoma, sans-serif;
            @if($embed)
            container-type: size;
            container-name: ssl-cert;
            @endif
        }
        .ssl-cert-page {
            position: absolute;
            top: 0;
            left: {{ $embed ? '125px' : '0' }};
            width: 100%;
            height: 100%;
            overflow: hidden;
            page-break-after: avoid;
            page-break-inside: avoid;
            page-break-before: avoid;
            background: {{ $bg }};
        }
        .ssl-cert-artwork {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: fill;
            z-index: 0;
            pointer-events: none;
        }
        .ssl-cert-frame {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: {{ $borderW > 0 ? round(($borderW / max($w, 0.1)) * 100, 4).'%' : '0' }} solid {{ $border }};
            pointer-events: none;
        }
        .ssl-cert-el {
            position: absolute;
            overflow: {{ $embed ? 'hidden' : 'visible' }};
        }
        .ssl-cert-el-text {
            white-space: pre-wrap;
            word-wrap: break-word;
            word-spacing: 0.12em;
            line-height: 1.15;
        }
        .ssl-cert-el-line {
            border-top-style: solid;
            border-top-width: 0.4mm;
        }
        .ssl-cert-sign-line {
            border-top: 0.35mm solid currentColor;
            margin: 2mm 0 1.5mm;
        }
        .ssl-cert-sign-name { font-weight: 700; font-size: 10pt; }
        .ssl-cert-sign-title { font-size: 8pt; }
        .ssl-cert-sign-img { max-height: 14mm; max-width: 100%; display: block; margin: 0 auto 1mm; }
        .ssl-cert-deco-signpost { width: 100%; height: 100%; }
        .ssl-cert-deco-signpost .pole {
            position: absolute;
            left: 8mm;
            top: 2mm;
            width: 2.2mm;
            height: 30mm;
            background: currentColor;
        }
        .ssl-cert-deco-signpost .blade {
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
        .ssl-cert-deco-ribbon {
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
        .ssl-cert-qr img { width: 100%; height: 100%; }
    </style>
@unless($embed)
</head>
<body>
@endunless
<div class="ssl-cert-root">
<div class="ssl-cert-page">
@if(!empty($backgroundUrl))
    <img class="ssl-cert-artwork" src="{{ $backgroundUrl }}" alt="">
@endif
@if($borderW > 0)
    <div class="ssl-cert-frame"></div>
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
        $rot = (float) ($el['rotation'] ?? 0);
        $op = $el['opacity'] ?? 1;
        $xf = $rot !== 0.0 ? 'transform:rotate('.$rot.'deg);' : '';
        $pct = is_array($el['box_percent'] ?? null) ? $el['box_percent'] : null;
        $fitWidth = ($el['width_mode'] ?? '') === 'fit-content';
        if ($embed && $pct) {
            $widthCss = $fitWidth ? 'width:fit-content;' : 'width:'.$pct['width'].'%;';
            $box = 'left:'.$pct['left'].'%;top:'.$pct['top'].'%;'.$widthCss.'height:'.$pct['height'].'%;opacity:'.$op.';'.$xf;
        } else {
            $widthCss = $fitWidth ? 'width:fit-content;' : "width:{$ew}mm;";
            $box = "left:{$x}mm;top:{$y}mm;{$widthCss}height:{$eh}mm;opacity:{$op};{$xf}";
        }
        $familyCss = \App\Modules\Certification\Application\Support\CertificateFontRegistry::quoteCssFamily(
            (string) ($el['font_family_css'] ?? $pdfFamily)
        );
        $lsCss = (!empty($forBrowser) ? $ls : 'normal');
        $elBg = is_string($el['background_color'] ?? null) ? $el['background_color'] : '';
        $valign = (string) ($el['vertical_align'] ?? '');
        $flex = ($valign === 'middle' && ! empty($embed))
            ? 'display:flex;align-items:center;justify-content:'.($align === 'left' ? 'flex-start' : ($align === 'right' ? 'flex-end' : 'center')).';'
            : 'display:block;';
        $fill = $elBg !== '' ? 'background:'.$elBg.';' : '';
    @endphp
    @if($type === 'text')
        <div class="ssl-cert-el ssl-cert-el-text" style="{{ $box }}{{ $flex }}{{ $fill }}color:{{ $color }};text-align:{{ $align }};font-weight:{{ $weight }};font-size:{{ $size }}pt;letter-spacing:{{ $lsCss }};direction:{{ $dir }};font-family:{{ $familyCss }};line-height:{{ $el['line_height'] ?? '1.15' }};unicode-bidi:isolate;">
            {!! nl2br($el['resolved'] ?? '') !!}
        </div>
    @elseif($type === 'line')
        <div class="ssl-cert-el ssl-cert-el-line" style="{{ $box }}border-top-color:{{ $color }};height:{{ max($eh, 1) }}mm;"></div>
    @elseif($type === 'image' && !empty($el['image_url']))
        <div class="ssl-cert-el" style="{{ $box }}">
            <img src="{{ $el['image_url'] }}" alt="" style="width:100%;height:100%;object-fit:{{ $el['object_fit'] ?? 'contain' }};">
        </div>
    @elseif($type === 'svg')
        <div class="ssl-cert-el" style="{{ $box }}overflow:hidden;">
            @if(!empty($el['image_url']))
                <img src="{{ $el['image_url'] }}" alt="" style="width:100%;height:100%;object-fit:{{ $el['object_fit'] ?? 'fill' }};">
            @elseif(!empty($el['svg_markup']))
                {!! $el['svg_markup'] !!}
            @endif
        </div>
    @elseif($type === 'qr' && !empty($el['qr_data_uri']))
        <div class="ssl-cert-el ssl-cert-qr" style="{{ $box }}">
            <img src="{{ $el['qr_data_uri'] }}" alt="QR">
        </div>
    @elseif($type === 'signature_block')
        <div class="ssl-cert-el" style="{{ $box }}color:{{ $color }};text-align:{{ $align }};">
            @if(!empty($el['signature_url']))
                <img class="ssl-cert-sign-img" src="{{ $el['signature_url'] }}" alt="">
            @else
                <div style="height:10mm;"></div>
            @endif
            <div class="ssl-cert-sign-line"></div>
            <div class="ssl-cert-sign-name">{{ $el['signer_name'] ?? '' }}</div>
            <div class="ssl-cert-sign-title">{{ $el['signer_title'] ?? '' }}</div>
        </div>
    @elseif($type === 'decoration')
        @if(($el['decoration'] ?? '') === 'ribbon')
            <div class="ssl-cert-el ssl-cert-deco-ribbon" style="{{ $box }}background:{{ $color }};color:{{ $el['accent_color'] ?? '#FCD500' }};">
                {{ $el['label'] ?? '' }}
            </div>
        @else
            <div class="ssl-cert-el ssl-cert-deco-signpost" style="{{ $box }}color:{{ $color }};">
                <div class="pole"></div>
                <div class="blade" style="color:{{ $el['accent_color'] ?? '#FCD500' }};">{{ $el['label'] ?? '' }}</div>
            </div>
        @endif
    @endif
@endforeach
</div>
</div>
@unless($embed)
</body>
</html>
@endunless
