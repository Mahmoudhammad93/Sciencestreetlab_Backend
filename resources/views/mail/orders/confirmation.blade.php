<x-mail::message>
# {{ __('mail.order_received_heading') }}

{{ __('mail.hello') }} {{ $customerName }},

{{ __('mail.order_received_body', ['order' => $order->order_number]) }}

- **{{ __('mail.order_number_label') }}:** {{ $order->order_number }}
- **{{ __('Order date') }}:** {{ $order->paid_at?->timezone(config('sciencestreet.timezone'))->toDayDateTimeString() ?? $order->created_at?->timezone(config('sciencestreet.timezone'))->toDayDateTimeString() }}
- **{{ __('Payment status') }}:** {{ $paymentStatus }}
- **{{ __('Currency') }}:** {{ $order->currency }}

@php
    $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
    $tracking = $order->bostaShipment?->tracking_number;
@endphp

@if (! empty($shipping))
**{{ __('Shipping address') }}:**  
{{ trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? '')) }}  
{{ $shipping['address'] ?? '' }}  
{{ $shipping['district'] ?? ($shipping['district_name'] ?? '') }} — {{ $shipping['city'] ?? '' }}  
{{ $shipping['phone'] ?? '' }}
@endif

@if ($tracking)
- **{{ __('mail.tracking_label') }}:** {{ $tracking }}
@endif

<x-mail::table>
| Product | Qty | Unit price | Total |
|:--------|----:|-----------:|------:|
@foreach ($order->items as $item)
@php
    $bookLang = \App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions::bookLanguageFromMetadata(
        is_array($item->metadata) ? $item->metadata : null
    );
    $itemLabel = $item->product_name;
    if ($bookLang !== null) {
        $itemLabel .= ' ('.__('mail.book_label').': '.\App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions::displayBookLanguage($bookLang).')';
    }
@endphp
| {{ $itemLabel }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->total_price, 2) }} |
@endforeach
</x-mail::table>

- **Subtotal:** {{ number_format((float) $order->subtotal, 2) }} {{ $order->currency }}
@if ((float) $order->discount_amount > 0)
- **Discount:** {{ number_format((float) $order->discount_amount, 2) }} {{ $order->currency }}
@endif
@if ((float) $order->shipping_amount > 0)
- **{{ __('mail.shipping_label') }}:** {{ number_format((float) $order->shipping_amount, 2) }} {{ $order->currency }}
@endif
- **Total:** {{ number_format((float) $order->total, 2) }} {{ $order->currency }}

@if ($order->requires_delivery_fulfillment && $order->fulfilled_at === null)
{{ __('mail.course_after_delivery_note') }}
@endif

<x-mail::button :url="$viewOrderUrl">
{{ $viewOrderLabel ?? __('mail.view_order') }}
</x-mail::button>

@if (! empty($accountActivationUrl))
<x-mail::button :url="$accountActivationUrl">
{{ $accountActivationLabel ?? __('mail.create_account_to_track_order') }}
</x-mail::button>
@endif

{{ __('mail.thanks') }},<br>
{{ config('sciencestreet.name', config('app.name')) }}
</x-mail::message>
