<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Events;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Domain signal that a catalog product changed and connected channels may need sync.
 * Listeners enqueue work — never call external APIs from the request lifecycle.
 */
final class ProductSalesChannelSyncRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Product $product,
    ) {}
}
