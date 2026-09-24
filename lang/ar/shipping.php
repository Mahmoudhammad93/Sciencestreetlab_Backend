<?php

declare(strict_types=1);

return [
    'status' => [
        'pending' => 'قيد التجهيز',
        'created' => 'تم إنشاء الشحنة',
        'picked_up' => 'تم استلام الشحنة',
        'in_transit' => 'الشحنة في الطريق',
        'out_for_delivery' => 'خرجت للتسليم',
        'delivered' => 'تم التسليم',
        'cancelled' => 'تم إلغاء الشحنة',
        'failed' => 'تعذر التسليم',
        'unknown' => 'جاري تحديث حالة الشحنة',
    ],
    'steps' => [
        'order_placed' => 'تم إنشاء الطلب',
        'shipment_created' => 'تم إنشاء الشحنة',
        'picked_up' => 'تم استلام الشحنة',
        'in_transit' => 'الشحنة في الطريق',
        'out_for_delivery' => 'خرجت للتسليم',
        'delivered' => 'تم التسليم',
        'course_activated' => 'تم تفعيل الكورس',
    ],
    'course_access' => [
        'locked' => 'يتم فتح الكورس بعد تسليم الشحنة.',
        'processing' => 'تم تأكيد التسليم. جاري تفعيل الكورس…',
        'active' => 'تم تفعيل الوصول للكورس.',
        'unlocks_on' => 'delivered',
    ],
];
