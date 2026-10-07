<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * Distinct visual presets derived from Certifications.ai (5 artboards).
 * Text content uses placeholders — no sample student/course hardcoding.
 */
final class CertificateLayoutPresets
{
    public const CYAN_LANDSCAPE_COMPLETION = 'cyan_landscape_completion';

    public const YELLOW_LANDSCAPE_COMPLETION = 'yellow_landscape_completion';

    public const YELLOW_PORTRAIT_ACHIEVEMENT = 'yellow_portrait_achievement';

    public const NAVY_LANDSCAPE_COMPLETION = 'navy_landscape_completion';

    public const NAVY_PORTRAIT_ACHIEVEMENT = 'navy_portrait_achievement';

    public const ARTWORK_LANDSCAPE_OVERLAY = 'artwork_landscape_overlay';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::CYAN_LANDSCAPE_COMPLETION => 'Landscape Completion — Cyan border',
            self::YELLOW_LANDSCAPE_COMPLETION => 'Landscape Completion — Yellow border',
            self::YELLOW_PORTRAIT_ACHIEVEMENT => 'Portrait Achievement — Yellow border',
            self::NAVY_LANDSCAPE_COMPLETION => 'Landscape Completion — Navy border',
            self::NAVY_PORTRAIT_ACHIEVEMENT => 'Portrait Achievement — Navy border',
        ];
    }

    /**
     * @return array{
     *   page: array<string, mixed>,
     *   elements: list<array<string, mixed>>,
     *   defaults: array<string, string>
     * }
     */
    public static function layout(string $presetKey): array
    {
        return match ($presetKey) {
            self::CYAN_LANDSCAPE_COMPLETION => self::landscapeCompletion(
                border: '#5BC8E1',
                nameColor: '#5BC8E1',
                accent: '#2828a0',
                deco: 'signpost',
            ),
            self::YELLOW_LANDSCAPE_COMPLETION => self::landscapeCompletion(
                border: '#FCD500',
                nameColor: '#FCD500',
                accent: '#1a2a5c',
                deco: 'signpost',
            ),
            self::NAVY_LANDSCAPE_COMPLETION => self::landscapeCompletion(
                border: '#1a2a5c',
                nameColor: '#FCD500',
                accent: '#1a2a5c',
                deco: 'signpost',
            ),
            self::YELLOW_PORTRAIT_ACHIEVEMENT => self::portraitAchievement(
                border: '#FCD500',
                accent: '#1a2a5c',
                ribbon: '#F97316',
            ),
            self::NAVY_PORTRAIT_ACHIEVEMENT => self::portraitAchievement(
                border: '#1a2a5c',
                accent: '#1a2a5c',
                ribbon: '#F97316',
            ),
            self::ARTWORK_LANDSCAPE_OVERLAY => self::artworkLandscapeOverlay(),
            default => self::landscapeCompletion(
                border: '#2828a0',
                nameColor: '#2828a0',
                accent: '#2828a0',
                deco: 'signpost',
            ),
        };
    }

    /**
     * Image-only admin templates: keep the uploaded artwork and overlay
     * the student name and course title. No extra chrome/border.
     *
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, string>}
     */
    public static function artworkLandscapeOverlay(?float $widthMm = null, ?float $heightMm = null): array
    {
        $widthMm = $widthMm ?? 297.0;
        $heightMm = $heightMm ?? 210.0;

        return [
            'page' => [
                'orientation' => $widthMm >= $heightMm ? 'landscape' : 'portrait',
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'background_color' => '#FFFFFF',
                'border_color' => '#FFFFFF',
                'border_width_mm' => 0,
                'preset' => self::ARTWORK_LANDSCAPE_OVERLAY,
            ],
            'defaults' => [],
            'elements' => [
                [
                    'id' => 'student',
                    'type' => 'text',
                    'cert_field' => 'student_name',
                    'content' => '{{ student_name }}',
                    'x' => $widthMm * 0.19,
                    'y' => $heightMm * 0.38,
                    'width' => $widthMm * 1.00,
                    'height' => $heightMm * 0.08,
                    'width_mode' => 'fit-content',
                    'z_index' => 20,
                    'font_family' => 'Cairo',
                    'font_size' => 22,
                    'font_weight' => '700',
                    'color' => '#1a2a5c',
                    'text_align' => 'center',
                    'vertical_align' => 'middle',
                ],
                [
                    'id' => 'course',
                    'type' => 'text',
                    'cert_field' => 'course_name',
                    'content' => '{{ course_name }}',
                    'x' => $widthMm * 0.14,
                    'y' => $heightMm * 0.455,
                    'width' => $widthMm * 0.72,
                    'height' => $heightMm * 0.065,
                    'z_index' => 20,
                    'font_family' => 'Cairo',
                    'font_size' => 14,
                    'font_weight' => '700',
                    'color' => '#1a2a5c',
                    'text_align' => 'center',
                    'vertical_align' => 'middle',
                ],
            ],
        ];
    }

    /**
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, string>}
     */
    private static function landscapeCompletion(string $border, string $nameColor, string $accent, string $deco): array
    {
        return [
            'page' => [
                'orientation' => 'landscape',
                'width_mm' => 297,
                'height_mm' => 210,
                'background_color' => '#FFFFFF',
                'border_color' => $border,
                'border_width_mm' => 8,
                'preset' => null,
            ],
            'defaults' => [
                'certificate_title' => 'CERTIFICATE',
                'certificate_subtitle' => 'OF COMPLETION',
                'intro_text' => 'THIS IS TO CERTIFY THAT',
                'achievement_text' => 'HAS COMPLETED {{ course_name }} WITH EXCELLENCE!',
                'signer_name' => 'Abdullah Annan',
                'signer_title' => 'Science Street Founder & CEO',
                'brand_name' => 'شارع العلوم',
            ],
            'elements' => [
                [
                    'id' => 'brand_tr',
                    'type' => 'text',
                    'content' => '{{ brand_name }}',
                    'x' => 210, 'y' => 14, 'width' => 70, 'height' => 14,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 16,
                    'font_weight' => '700', 'color' => $accent, 'text_align' => 'right',
                    'dir' => 'rtl',
                ],
                [
                    'id' => 'title',
                    'type' => 'text',
                    'content' => '{{ certificate_title }}',
                    'x' => 30, 'y' => 36, 'width' => 237, 'height' => 16,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 28,
                    'font_weight' => '700', 'color' => $accent, 'text_align' => 'center',
                ],
                [
                    'id' => 'subtitle',
                    'type' => 'text',
                    'content' => '{{ certificate_subtitle }}',
                    'x' => 30, 'y' => 54, 'width' => 237, 'height' => 12,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 16,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'center',
                    'letter_spacing' => '2px',
                ],
                [
                    'id' => 'intro',
                    'type' => 'text',
                    'content' => '{{ intro_text }}',
                    'x' => 30, 'y' => 78, 'width' => 237, 'height' => 8,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 11,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'center',
                ],
                [
                    'id' => 'student',
                    'type' => 'text',
                    'content' => '{{ student_name }}',
                    'x' => 30, 'y' => 92, 'width' => 237, 'height' => 18,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 30,
                    'font_weight' => '700', 'color' => $nameColor, 'text_align' => 'center',
                ],
                [
                    'id' => 'divider',
                    'type' => 'line',
                    'x' => 95, 'y' => 114, 'width' => 107, 'height' => 1,
                    'z_index' => 5, 'color' => $accent,
                ],
                [
                    'id' => 'achievement',
                    'type' => 'text',
                    'content' => '{{ achievement_text }}',
                    'x' => 40, 'y' => 120, 'width' => 217, 'height' => 20,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 13,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'center',
                ],
                [
                    'id' => 'deco_bl',
                    'type' => 'decoration',
                    'decoration' => $deco,
                    'x' => 18, 'y' => 155, 'width' => 55, 'height' => 36,
                    'z_index' => 8, 'color' => $accent, 'accent_color' => '#FCD500',
                ],
                [
                    'id' => 'signature_block',
                    'type' => 'signature_block',
                    'x' => 195, 'y' => 150, 'width' => 75, 'height' => 40,
                    'z_index' => 10, 'color' => $accent, 'text_align' => 'center',
                ],
                [
                    'id' => 'meta',
                    'type' => 'text',
                    'content' => '{{ certificate_number }}  ·  {{ issue_date }}',
                    'x' => 30, 'y' => 192, 'width' => 180, 'height' => 8,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 8,
                    'font_weight' => '400', 'color' => '#666666', 'text_align' => 'left',
                ],
                [
                    'id' => 'qr',
                    'type' => 'qr',
                    'x' => 255, 'y' => 168, 'width' => 24, 'height' => 24,
                    'z_index' => 12,
                ],
            ],
        ];
    }

    /**
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, string>}
     */
    private static function portraitAchievement(string $border, string $accent, string $ribbon): array
    {
        return [
            'page' => [
                'orientation' => 'portrait',
                'width_mm' => 210,
                'height_mm' => 297,
                'background_color' => '#FFFFFF',
                'border_color' => $border,
                'border_width_mm' => 8,
                'preset' => null,
            ],
            'defaults' => [
                'certificate_title' => 'CERTIFICATE',
                'certificate_subtitle' => 'OF ACHIEVEMENT',
                'intro_text' => 'THIS IS TO CERTIFY THAT',
                'achievement_text' => 'This certificate awarded for accomplished {{ course_name }} successfully.',
                'signer_name' => 'Abdullah Annan',
                'signer_title' => 'Science Street Founder & CEO',
                'brand_name' => 'شارع العلوم',
            ],
            'elements' => [
                [
                    'id' => 'ribbon',
                    'type' => 'decoration',
                    'decoration' => 'ribbon',
                    'x' => 168, 'y' => 0, 'width' => 28, 'height' => 70,
                    'z_index' => 8, 'color' => $ribbon, 'accent_color' => '#FCD500',
                ],
                [
                    'id' => 'title',
                    'type' => 'text',
                    'content' => '{{ certificate_title }}',
                    'x' => 18, 'y' => 36, 'width' => 140, 'height' => 16,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 26,
                    'font_weight' => '700', 'color' => $accent, 'text_align' => 'left',
                ],
                [
                    'id' => 'subtitle',
                    'type' => 'text',
                    'content' => '{{ certificate_subtitle }}',
                    'x' => 18, 'y' => 54, 'width' => 140, 'height' => 12,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 14,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'left',
                ],
                [
                    'id' => 'intro',
                    'type' => 'text',
                    'content' => '{{ intro_text }}',
                    'x' => 18, 'y' => 90, 'width' => 170, 'height' => 8,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 11,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'left',
                ],
                [
                    'id' => 'student',
                    'type' => 'text',
                    'content' => '{{ student_name }}',
                    'x' => 18, 'y' => 108, 'width' => 170, 'height' => 18,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 28,
                    'font_weight' => '700', 'color' => $accent, 'text_align' => 'left',
                ],
                [
                    'id' => 'achievement',
                    'type' => 'text',
                    'content' => '{{ achievement_text }}',
                    'x' => 18, 'y' => 140, 'width' => 160, 'height' => 28,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 12,
                    'font_weight' => '400', 'color' => $accent, 'text_align' => 'left',
                ],
                [
                    'id' => 'brand_bl',
                    'type' => 'text',
                    'content' => '{{ brand_name }}',
                    'x' => 18, 'y' => 230, 'width' => 70, 'height' => 14,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 14,
                    'font_weight' => '700', 'color' => $accent, 'text_align' => 'left',
                    'dir' => 'rtl',
                ],
                [
                    'id' => 'signature_block',
                    'type' => 'signature_block',
                    'x' => 120, 'y' => 230, 'width' => 70, 'height' => 40,
                    'z_index' => 10, 'color' => $accent, 'text_align' => 'center',
                ],
                [
                    'id' => 'meta',
                    'type' => 'text',
                    'content' => '{{ certificate_number }}  ·  {{ issue_date }}',
                    'x' => 18, 'y' => 275, 'width' => 140, 'height' => 8,
                    'z_index' => 10, 'font_family' => 'DejaVu Sans', 'font_size' => 8,
                    'font_weight' => '400', 'color' => '#666666', 'text_align' => 'left',
                ],
                [
                    'id' => 'qr',
                    'type' => 'qr',
                    'x' => 168, 'y' => 255, 'width' => 24, 'height' => 24,
                    'z_index' => 12,
                ],
            ],
        ];
    }
}
