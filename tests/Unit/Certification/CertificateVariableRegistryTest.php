<?php

declare(strict_types=1);

namespace Tests\Unit\Certification;

use App\Modules\Certification\Application\Support\CertificateVariableRegistry;
use PHPUnit\Framework\TestCase;

final class CertificateVariableRegistryTest extends TestCase
{
    public function test_resolves_known_variables(): void
    {
        $out = CertificateVariableRegistry::resolve(
            'Hello {{ student_name }} — {{ course_name }}',
            ['student_name' => 'Ahmed Mohamed', 'course_name' => 'Microscope Course']
        );

        $this->assertSame('Hello Ahmed Mohamed — Microscope Course', $out);
    }

    public function test_unknown_variables_become_empty(): void
    {
        $out = CertificateVariableRegistry::resolve(
            'X{{ unknown_field }}Y {{ student_name }}',
            ['student_name' => 'Sara']
        );

        $this->assertSame('XY Sara', $out);
    }

    public function test_helper_text_lists_whitelist(): void
    {
        $help = CertificateVariableRegistry::helperText();
        $this->assertStringContainsString('{{ student_name }}', $help);
        $this->assertStringContainsString('{{ verification_url }}', $help);
    }
}
