<?php

declare(strict_types=1);

return [
    'status' => [
        'pending' => 'Pending',
        'created' => 'Shipment Created',
        'picked_up' => 'Picked Up',
        'in_transit' => 'In Transit',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'failed' => 'Delivery Failed',
        'unknown' => 'Status Updating',
    ],
    'steps' => [
        'order_placed' => 'Order Placed',
        'shipment_created' => 'Shipment Created',
        'picked_up' => 'Picked Up',
        'in_transit' => 'In Transit',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'course_activated' => 'Course Activated',
    ],
    'course_access' => [
        'locked' => 'Course access unlocks when the shipment is delivered.',
        'processing' => 'Delivery confirmed. Activating course access…',
        'active' => 'Course access is active.',
        'unlocks_on' => 'delivered',
    ],
];
