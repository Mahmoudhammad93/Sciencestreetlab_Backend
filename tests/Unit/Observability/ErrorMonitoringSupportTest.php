<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Observability\Application\Services\ErrorFingerprint;
use App\Modules\Observability\Application\Services\ErrorFrameParser;
use App\Modules\Observability\Application\Services\ErrorModuleDetector;
use App\Modules\Observability\Application\Services\ErrorPathNormalizer;
use App\Modules\Observability\Application\Services\ErrorRedactor;
use App\Modules\Observability\Http\Controllers\Api\ErrorProbeController;
use RuntimeException;
use Tests\TestCase;

final class ErrorMonitoringSupportTest extends TestCase
{
    public function test_module_detection_from_namespace_and_file(): void
    {
        $detector = app(ErrorModuleDetector::class);
        $frames = [[
            'file' => 'app/Modules/Certification/Application/Services/IssueCertificate.php',
            'line' => 87,
            'class' => 'App\\Modules\\Certification\\Application\\Services\\IssueCertificate',
            'function' => 'handle',
            'application' => true,
        ]];

        $this->assertSame('Certification', $detector->detect($frames, null, null, null, null, null, null, 'http'));
        $this->assertSame('Commerce', $detector->detect([], null, null, null, 'App\\Modules\\Commerce\\Jobs\\SyncBosta', null, null, 'queue'));
        $this->assertSame('Frontend', $detector->detect([], null, null, '/checkout', null, null, null, 'frontend'));
    }

    public function test_application_class_and_method_from_frames(): void
    {
        $parser = app(ErrorFrameParser::class);
        $frames = [
            [
                'file' => 'vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php',
                'line' => 1,
                'class' => 'Illuminate\\Pipeline\\Pipeline',
                'function' => 'then',
                'application' => false,
            ],
            [
                'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
                'line' => 40,
                'class' => 'App\\Modules\\Commerce\\Application\\Services\\CheckoutService',
                'function' => 'createOrder',
                'application' => true,
            ],
        ];

        $app = $parser->applicationClass($frames);
        $this->assertSame('App\\Modules\\Commerce\\Application\\Services\\CheckoutService', $app['class']);
        $this->assertSame('createOrder', $app['method']);
    }

    public function test_model_detection_only_when_eloquent_model_is_in_stack(): void
    {
        $parser = app(ErrorFrameParser::class);
        $known = $parser->eloquentModel([[
            'file' => 'app/Modules/Commerce/Infrastructure/Persistence/Models/Order.php',
            'line' => 40,
            'class' => Order::class,
            'function' => 'save',
            'application' => true,
        ]]);
        $unknown = $parser->eloquentModel([[
            'file' => 'app/Modules/Observability/Http/Controllers/Api/ErrorProbeController.php',
            'line' => 16,
            'class' => ErrorProbeController::class,
            'function' => 'boom',
            'application' => true,
        ]]);

        $this->assertSame('Order', $known);
        $this->assertNull($unknown);
    }

    public function test_fingerprint_normalizes_variable_messages(): void
    {
        $fp = app(ErrorFingerprint::class);
        $a = $fp->make([
            'exception_class' => RuntimeException::class,
            'message' => 'Order SS-ABCDEF12 failed for 550e8400-e29b-41d4-a716-446655440000 user 12',
            'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
            'line' => 40,
            'route_name' => 'api.checkout',
            'module' => 'Commerce',
            'source' => 'http',
        ]);
        $b = $fp->make([
            'exception_class' => RuntimeException::class,
            'message' => 'Order SS-ZZZZZZZZ failed for 11111111-1111-4111-8111-111111111111 user 99',
            'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
            'line' => 40,
            'route_name' => 'api.checkout',
            'module' => 'Commerce',
            'source' => 'http',
        ]);
        $other = $fp->make([
            'exception_class' => RuntimeException::class,
            'message' => 'Payment declined',
            'file' => 'app/Modules/Commerce/Application/Services/CheckoutService.php',
            'line' => 40,
            'route_name' => 'api.checkout',
            'module' => 'Commerce',
            'source' => 'http',
        ]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $other);
    }

    public function test_redactor_redacts_sensitive_keys(): void
    {
        $redactor = app(ErrorRedactor::class);
        $clean = $redactor->redact([
            'password' => 'hunter2',
            'token' => 'abc',
            'Authorization' => 'Bearer xyz',
            'cookie' => 'sid=1',
            'api_key' => 'k',
            'card_number' => '4242',
            'order_id' => 15,
        ]);

        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('[REDACTED]', $clean['token']);
        $this->assertSame('[REDACTED]', $clean['Authorization']);
        $this->assertSame('[REDACTED]', $clean['cookie']);
        $this->assertSame('[REDACTED]', $clean['api_key']);
        $this->assertSame('[REDACTED]', $clean['card_number']);
        $this->assertSame(15, $clean['order_id']);
    }

    public function test_path_normalizer_strips_absolute_prefixes(): void
    {
        $normalizer = app(ErrorPathNormalizer::class);
        $this->assertSame(
            'app/Modules/Certification/Application/Issue.php',
            $normalizer->normalize(base_path('app/Modules/Certification/Application/Issue.php'))
        );
        $this->assertSame(
            'app/Modules/Certification/Application/Issue.php',
            $normalizer->normalize('/var/www/app/Modules/Certification/Application/Issue.php')
        );
    }
}
