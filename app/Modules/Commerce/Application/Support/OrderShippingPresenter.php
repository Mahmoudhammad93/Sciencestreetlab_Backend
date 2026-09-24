<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Carbon\CarbonInterface;

/**
 * Builds a frontend-safe shipping tracker payload for an order.
 * Does not expose shipment metadata, webhook payloads, or secrets.
 *
 * Tracker semantics: the step matching the current shipment state is
 * marked "current". Prior steps are "completed". Later steps are "pending".
 * After DELIVERED (before fulfillment), Delivered is completed and
 * Course Activated is current.
 */
final class OrderShippingPresenter
{
    /**
     * @var list<string>
     */
    private const JOURNEY_STEPS = [
        'order_placed',
        'shipment_created',
        'picked_up',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'course_activated',
    ];

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order): array
    {
        if (! $order->requires_delivery_fulfillment) {
            return [
                'required' => false,
                'provider' => null,
                'status' => null,
                'status_label' => null,
                'tracking_number' => null,
                'tracking_url' => null,
                'shipped_at' => null,
                'delivered_at' => null,
                'course_access' => [
                    'status' => $order->fulfilled_at !== null ? 'active' : 'not_applicable',
                    'unlocks_on' => null,
                    'message' => null,
                ],
                'progress' => null,
                'steps' => [],
            ];
        }

        $order->loadMissing(['bostaShipment']);
        $shipment = $order->bostaShipment;
        $status = $shipment?->status ?? ShipmentStatus::Pending;
        $courseAccess = $this->courseAccess($order, $status);
        $steps = $this->buildSteps($order, $shipment, $status, $courseAccess['status']);
        $progress = $this->buildProgress($steps, $status);

        return [
            'required' => true,
            'provider' => $shipment?->provider?->value ?? 'bosta',
            'status' => $status->value,
            'status_label' => $status->label(),
            'tracking_number' => $shipment?->tracking_number,
            'tracking_url' => $shipment?->tracking_url,
            'shipped_at' => $this->iso($shipment?->shipped_at),
            'delivered_at' => $this->iso($shipment?->delivered_at ?? $order->delivered_at),
            'course_access' => $courseAccess,
            'progress' => $progress,
            'steps' => $steps,
        ];
    }

    /**
     * @return array{status: string, unlocks_on: string, message: string}
     */
    private function courseAccess(Order $order, ShipmentStatus $status): array
    {
        if ($order->fulfilled_at !== null) {
            return [
                'status' => 'active',
                'unlocks_on' => 'delivered',
                'message' => (string) __('shipping.course_access.active'),
            ];
        }

        if ($status === ShipmentStatus::Delivered) {
            return [
                'status' => 'processing',
                'unlocks_on' => 'delivered',
                'message' => (string) __('shipping.course_access.processing'),
            ];
        }

        return [
            'status' => 'locked',
            'unlocks_on' => 'delivered',
            'message' => (string) __('shipping.course_access.locked'),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, completed_at: ?string}>
     */
    private function buildSteps(
        Order $order,
        ?Shipment $shipment,
        ShipmentStatus $status,
        string $courseAccessStatus,
    ): array {
        $courseActivated = $courseAccessStatus === 'active';
        $isFailure = $status->isTerminalFailure();

        // Index of the step that should be "current" (or first incomplete on failure).
        // When fulfilled, all steps are completed (no current).
        $currentIndex = $courseActivated
            ? null
            : $this->currentStepIndex($status, $shipment, $courseAccessStatus);

        $steps = [];
        foreach (self::JOURNEY_STEPS as $index => $key) {
            $label = (string) __('shipping.steps.'.$key);

            if ($courseActivated || ($currentIndex !== null && $index < $currentIndex)) {
                $steps[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => 'completed',
                    'completed_at' => $this->completedAtForStep($key, $order, $shipment),
                ];

                continue;
            }

            if ($isFailure && $currentIndex !== null && $index === $currentIndex) {
                $steps[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => 'failed',
                    'completed_at' => null,
                ];

                continue;
            }

            if ($currentIndex !== null && $index === $currentIndex) {
                $steps[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => 'current',
                    'completed_at' => null,
                ];

                continue;
            }

            $steps[] = [
                'key' => $key,
                'label' => $label,
                'status' => 'pending',
                'completed_at' => null,
            ];
        }

        return $steps;
    }

    /**
     * 0-based index into JOURNEY_STEPS for the highlighted current/failed step.
     */
    private function currentStepIndex(
        ShipmentStatus $status,
        ?Shipment $shipment,
        string $courseAccessStatus,
    ): int {
        if ($courseAccessStatus === 'processing' || $status === ShipmentStatus::Delivered) {
            // Delivered is done; highlight Course Activated while fulfillment runs.
            return array_search('course_activated', self::JOURNEY_STEPS, true);
        }

        if ($status->isTerminalFailure()) {
            // Halt on the first step after proven completions (order + optional shipment_created).
            return $shipment !== null
                ? array_search('picked_up', self::JOURNEY_STEPS, true)
                : array_search('shipment_created', self::JOURNEY_STEPS, true);
        }

        if ($status === ShipmentStatus::Unknown) {
            // Never invent progress toward delivered.
            return array_search('shipment_created', self::JOURNEY_STEPS, true);
        }

        if ($status === ShipmentStatus::Pending) {
            return array_search('shipment_created', self::JOURNEY_STEPS, true);
        }

        return match ($status) {
            ShipmentStatus::Created => array_search('shipment_created', self::JOURNEY_STEPS, true),
            ShipmentStatus::PickedUp => array_search('picked_up', self::JOURNEY_STEPS, true),
            ShipmentStatus::InTransit => array_search('in_transit', self::JOURNEY_STEPS, true),
            ShipmentStatus::OutForDelivery => array_search('out_for_delivery', self::JOURNEY_STEPS, true),
            default => array_search('shipment_created', self::JOURNEY_STEPS, true),
        };
    }

    private function completedAtForStep(string $key, Order $order, ?Shipment $shipment): ?string
    {
        return match ($key) {
            'order_placed' => $this->iso($order->created_at),
            'shipment_created' => $this->iso($shipment?->created_at),
            // Only shipped_at is persisted for mid-journey stages.
            'picked_up', 'in_transit', 'out_for_delivery' => $this->iso($shipment?->shipped_at),
            'delivered' => $this->iso($shipment?->delivered_at ?? $order->delivered_at),
            'course_activated' => $this->iso($order->fulfilled_at),
            default => null,
        };
    }

    /**
     * @param  list<array{key: string, label: string, status: string, completed_at: ?string}>  $steps
     * @return array{current_step: int, total_steps: int, percentage: int, halted: bool, halt_reason: ?string}
     */
    private function buildProgress(array $steps, ShipmentStatus $status): array
    {
        $total = count($steps);
        $completed = 0;
        $currentStep = $total;

        foreach ($steps as $index => $step) {
            if ($step['status'] === 'completed') {
                $completed++;
                continue;
            }

            if (in_array($step['status'], ['current', 'failed'], true)) {
                $currentStep = $index + 1;
                break;
            }
        }

        // Deterministic: completed steps / total. Fulfilled → 100%.
        // Current step is highlighted but not counted as completed until left behind.
        $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'current_step' => $currentStep,
            'total_steps' => $total,
            'percentage' => $percentage,
            'halted' => $status->isTerminalFailure(),
            'halt_reason' => $status->isTerminalFailure() ? $status->value : null,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return null;
    }
}
