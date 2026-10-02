<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;

/**
 * Builds official Bosta specs.packageDetails from persisted order items.
 *
 * Official Create Delivery contract (POST /api/v2/deliveries?apiVersion=1) supports
 * packageDetails.description + packageDetails.itemsCount only — not structured
 * product rows, unit prices, or product image fields.
 *
 * @see https://docs.bosta.co/docs/how-to/create-your-first-delivery/
 */
final class BostaPackageDetailsBuilder
{
    /**
     * @return array{description: string, itemsCount: int}
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['items.product']);

        $physical = $order->items
            ->filter(fn (OrderItem $item): bool => $this->isPhysicalShipmentItem($item))
            ->values();

        $itemsCount = (int) $physical->sum(fn (OrderItem $item): int => max(0, (int) $item->quantity));

        $parts = [];
        $multiLine = $physical->count() > 1;

        foreach ($physical as $item) {
            $name = $this->itemDisplayName($item);
            $qty = max(1, (int) $item->quantity);

            if ($multiLine || $qty > 1) {
                $parts[] = $name.' × '.$qty;
            } else {
                $parts[] = $name;
            }
        }

        $description = $parts !== []
            ? implode('، ', $parts)
            : 'Physical package';

        return [
            'description' => $description,
            // Bosta expects a positive itemsCount for deliverable packages.
            'itemsCount' => max(1, $itemsCount),
        ];
    }

    public function isPhysicalShipmentItem(OrderItem $item): bool
    {
        $type = $item->metadata['product_type'] ?? null;
        if (is_string($type) && $type !== '') {
            return $type === ProductType::Kit->value || $type === ProductType::Bundle->value;
        }

        $productType = $item->product?->type;

        return $productType === ProductType::Kit || $productType === ProductType::Bundle;
    }

    private function itemDisplayName(OrderItem $item): string
    {
        $name = trim((string) $item->product_name);
        if ($name !== '') {
            return $name;
        }

        $sku = trim((string) $item->product_sku);
        if ($sku !== '') {
            return $sku;
        }

        return 'Product';
    }
}
