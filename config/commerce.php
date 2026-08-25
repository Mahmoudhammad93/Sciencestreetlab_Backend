<?php

declare(strict_types=1);

return [
    /** Supported: myfatoorah, paymob */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'myfatoorah'),
];
