<?php

declare(strict_types=1);

namespace Tests\Unit\Certification;

use App\Modules\Certification\Application\Support\CertificateCanvasGeometry;
use PHPUnit\Framework\TestCase;

final class CertificateCanvasGeometryTest extends TestCase
{
    public function test_percent_boxes_are_relative_to_template_canvas(): void
    {
        $pct = CertificateCanvasGeometry::boxToPercent(29.7, 21.0, 59.4, 42.0, 297, 210);

        $this->assertEqualsWithDelta(10.0, $pct['left'], 0.001);
        $this->assertEqualsWithDelta(10.0, $pct['top'], 0.001);
        $this->assertEqualsWithDelta(20.0, $pct['width'], 0.001);
        $this->assertEqualsWithDelta(20.0, $pct['height'], 0.001);
    }

    public function test_mm_to_pt_matches_dompdf_paper_conversion(): void
    {
        $this->assertEqualsWithDelta(297 * 72 / 25.4, CertificateCanvasGeometry::mmToPt(297), 0.01);
        $this->assertEqualsWithDelta(210 * 72 / 25.4, CertificateCanvasGeometry::mmToPt(210), 0.01);
    }

    public function test_contain_scale_is_uniform_and_fits(): void
    {
        $this->assertEqualsWithDelta(0.5, CertificateCanvasGeometry::containScale(400, 200, 800, 400), 0.0001);
        $this->assertEqualsWithDelta(0.5, CertificateCanvasGeometry::containScale(500, 200, 800, 400), 0.0001);
        $this->assertEqualsWithDelta(1.125, CertificateCanvasGeometry::containScale(900, 500, 800, 400), 0.0001);
    }

    public function test_boxes_are_clamped_inside_the_canvas(): void
    {
        $box = CertificateCanvasGeometry::clampBox(290, 200, 40, 30, 297, 210);

        $this->assertGreaterThanOrEqual(0, $box['x']);
        $this->assertGreaterThanOrEqual(0, $box['y']);
        $this->assertLessThanOrEqual(297, $box['x'] + $box['width']);
        $this->assertLessThanOrEqual(210, $box['y'] + $box['height']);
    }

    public function test_aspect_ratio_is_width_over_height(): void
    {
        $this->assertEqualsWithDelta(297 / 210, CertificateCanvasGeometry::aspectRatio(297, 210), 0.0001);
    }

    public function test_raster_page_converts_pixels_at_css_dpi(): void
    {
        $page = CertificateCanvasGeometry::pageFromRaster('/this/file/does-not-exist.jpeg');

        $this->assertEqualsWithDelta(1024 * 25.4 / 96, $page['width_mm'], 0.01);
        $this->assertEqualsWithDelta(578 * 25.4 / 96, $page['height_mm'], 0.01);
        $this->assertSame('landscape', $page['orientation']);
    }
}
