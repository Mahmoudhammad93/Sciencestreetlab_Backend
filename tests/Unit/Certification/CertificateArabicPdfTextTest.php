<?php

declare(strict_types=1);

namespace Tests\Unit\Certification;

use App\Modules\Certification\Application\Support\CertificateArabicPdfText;
use PHPUnit\Framework\TestCase;

final class CertificateArabicPdfTextTest extends TestCase
{
    public function test_detects_arabic_and_keeps_identifiers_ltr(): void
    {
        $this->assertTrue(CertificateArabicPdfText::containsArabic('كورس الميكروسكوب'));
        $this->assertFalse(CertificateArabicPdfText::containsArabic('CERTIFICATE'));

        $this->assertSame('ltr', CertificateArabicPdfText::resolveDirection(
            ['cert_field' => 'certificate_number'],
            'SSL-TEST-000001'
        ));
        $this->assertSame('rtl', CertificateArabicPdfText::resolveDirection(
            ['type' => 'text'],
            'كورس الميكروسكوب'
        ));
        $this->assertSame('ltr', CertificateArabicPdfText::resolveDirection(
            ['type' => 'text', 'dir' => 'ltr'],
            'CERTIFICATE'
        ));
    }

    public function test_pdf_shaping_changes_arabic_presentation_when_arphp_is_available(): void
    {
        $source = 'كورس الميكروسكوب';
        $shaped = CertificateArabicPdfText::shapeForPdf($source);

        if (class_exists(\ArPHP\I18N\Arabic::class)) {
            $this->assertNotSame($source, $shaped);
            $this->assertNotSame('', $shaped);
        } else {
            $this->assertSame($source, $shaped);
        }

        $this->assertSame('Ahmed Mohamed', CertificateArabicPdfText::shapeForPdf('Ahmed Mohamed'));
    }
}
