<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

final class ErrorModuleDetector
{
    /** @var array<string, string> */
    private const FILAMENT_RESOURCE_MAP = [
        'OrderResource' => 'Commerce',
        'ShipmentResource' => 'Commerce',
        'CouponResource' => 'Commerce',
        'ShippingRateResource' => 'Commerce',
        'ProductResource' => 'Catalog',
        'CategoryResource' => 'Catalog',
        'ProductReviewResource' => 'Catalog',
        'CourseResource' => 'Learning',
        'EnrollmentResource' => 'Learning',
        'TopicResource' => 'Learning',
        'AchievementResource' => 'Gamification',
        'CertificateTemplateResource' => 'Certification',
        'CertificateResource' => 'Certification',
        'QuizResource' => 'Assessment',
        'QuizAttemptResource' => 'Assessment',
        'QuestionResource' => 'Assessment',
        'QuestionBankResource' => 'Assessment',
        'InteractiveActivityResource' => 'Assessment',
        'CompetitionResource' => 'Competition',
        'CompetitionSubmissionResource' => 'Competition',
        'UserResource' => 'Identity',
        'HomeSlideResource' => 'Content',
        'BlogPostResource' => 'Content',
        'PageResource' => 'Content',
        'SalesChannelResource' => 'SocialCommerce',
        'SocialCampaignResource' => 'SocialAttribution',
        'SocialContentResource' => 'SocialAttribution',
        'TrackingLinkResource' => 'SocialAttribution',
        'ErrorIncidentResource' => 'System',
    ];

    /**
     * @param  list<array{file:?string,line:?int,class:?string,function:?string,application:bool}>  $frames
     */
    public function detect(
        array $frames,
        ?string $file,
        ?string $routeName,
        ?string $path,
        ?string $jobClass,
        ?string $command,
        ?string $controllerClass,
        string $source,
    ): string {
        foreach ([$jobClass, $controllerClass, $command] as $hint) {
            $fromNamespace = $this->fromNamespace($hint);
            if ($fromNamespace !== null) {
                return $fromNamespace;
            }
        }

        foreach ($frames as $frame) {
            $fromNamespace = $this->fromNamespace($frame['class']);
            if ($fromNamespace !== null) {
                return $fromNamespace;
            }
            $fromFile = $this->fromFile($frame['file']);
            if ($fromFile !== null) {
                return $fromFile;
            }
            $fromFilament = $this->fromFilamentClass($frame['class']);
            if ($fromFilament !== null) {
                return $fromFilament;
            }
        }

        $fromFile = $this->fromFile($file);
        if ($fromFile !== null) {
            return $fromFile;
        }

        $fromRoute = $this->fromRouteName($routeName);
        if ($fromRoute !== null) {
            return $fromRoute;
        }

        if ($source === 'frontend') {
            return 'Frontend';
        }

        if (is_string($path) && (str_starts_with($path, '/admin') || str_starts_with($path, 'admin'))) {
            return 'System';
        }

        return 'System';
    }

    private function fromNamespace(?string $class): ?string
    {
        if (! is_string($class) || ! preg_match('#App\\\\Modules\\\\([A-Za-z]+)#', $class, $m)) {
            return null;
        }

        return $m[1];
    }

    private function fromFile(?string $file): ?string
    {
        if (! is_string($file) || ! preg_match('#(?:^|/)(?:app/)?Modules/([A-Za-z]+)/#', $file, $m)) {
            return null;
        }

        return $m[1];
    }

    private function fromFilamentClass(?string $class): ?string
    {
        if (! is_string($class)) {
            return null;
        }
        foreach (self::FILAMENT_RESOURCE_MAP as $resource => $module) {
            if (str_contains($class, $resource)) {
                return $module;
            }
        }

        return null;
    }

    private function fromRouteName(?string $routeName): ?string
    {
        if (! is_string($routeName) || $routeName === '') {
            return null;
        }
        $map = [
            'filament.admin.resources.orders' => 'Commerce',
            'filament.admin.resources.products' => 'Catalog',
            'filament.admin.resources.courses' => 'Learning',
            'filament.admin.resources.users' => 'Identity',
            'filament.admin.resources.competitions' => 'Competition',
            'filament.admin.resources.quizzes' => 'Assessment',
        ];
        foreach ($map as $prefix => $module) {
            if (str_starts_with($routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }
}
