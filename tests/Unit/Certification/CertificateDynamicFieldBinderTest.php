<?php

declare(strict_types=1);

namespace Tests\Unit\Certification;

use App\Modules\Certification\Application\Support\CertificateDynamicFieldBinder;
use PHPUnit\Framework\TestCase;

final class CertificateDynamicFieldBinderTest extends TestCase
{
    public function test_binds_student_into_imported_name_box_without_moving_it(): void
    {
        $layout = [
            'page' => ['width_mm' => 297, 'height_mm' => 210, 'orientation' => 'landscape'],
            'elements' => [
                ['id' => 'title', 'type' => 'text', 'content' => 'CERTIFICATE', 'x' => 20, 'y' => 30, 'width' => 250, 'height' => 16, 'font_size' => 52],
                ['id' => 'name', 'type' => 'text', 'content' => 'DOAA ELSAYED', 'x' => 24, 'y' => 82.95, 'width' => 249, 'height' => 18, 'font_size' => 44.62],
                ['id' => 'body', 'type' => 'text', 'content' => 'HAS COMPLETED SCIENCE STREET SUMMER CAMP 2024 WITH EXCELLENCE!', 'x' => 40, 'y' => 120, 'width' => 217, 'height' => 20, 'font_size' => 16],
            ],
        ];

        $bound = CertificateDynamicFieldBinder::bind($layout, ['student_name' => 'Ahmed Mohamed']);
        $name = collect($bound['elements'])->firstWhere('id', 'name');
        $body = collect($bound['elements'])->firstWhere('id', 'body');

        $this->assertSame('{{ student_name }}', $name['content']);
        $this->assertSame('student_name', $name['cert_field']);
        $this->assertSame(24.0, (float) $name['x']);
        $this->assertSame(82.95, (float) $name['y']);
        $this->assertSame('HAS COMPLETED SCIENCE STREET SUMMER CAMP 2024 WITH EXCELLENCE!', $body['content']);
        $this->assertCount(3, $bound['elements']);
    }

    public function test_does_not_infer_a_course_field(): void
    {
        $this->assertFalse(CertificateDynamicFieldBinder::looksLikePersonName('HAS COMPLETED SCIENCE STREET SUMMER CAMP 2024 WITH EXCELLENCE!'));
        $this->assertTrue(CertificateDynamicFieldBinder::looksLikePersonName('DOAA ELSAYED'));
        $this->assertTrue(CertificateDynamicFieldBinder::looksLikePersonName('أحمد محمد'));
    }
}
