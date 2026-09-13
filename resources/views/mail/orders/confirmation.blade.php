<x-mail::message>
# Order confirmed

Hello {{ $customerName }},

Your payment was successful. This email confirms order **{{ $order->order_number }}**.

- **Order number:** {{ $order->order_number }}
- **Order date:** {{ $order->paid_at?->timezone(config('sciencestreet.timezone'))->toDayDateTimeString() ?? $order->created_at?->timezone(config('sciencestreet.timezone'))->toDayDateTimeString() }}
- **Payment status:** {{ $paymentStatus }}
- **Currency:** {{ $order->currency }}

<x-mail::table>
| Product | Qty | Unit price | Total |
|:--------|----:|-----------:|------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->total_price, 2) }} |
@endforeach
</x-mail::table>

- **Subtotal:** {{ number_format((float) $order->subtotal, 2) }} {{ $order->currency }}
@if ((float) $order->discount_amount > 0)
- **Discount:** {{ number_format((float) $order->discount_amount, 2) }} {{ $order->currency }}
@endif
- **Total:** {{ number_format((float) $order->total, 2) }} {{ $order->currency }}

Reference: {{ $order->order_number }}

<x-mail::button :url="$viewOrderUrl">
View order
</x-mail::button>

Thanks,<br>
{{ config('sciencestreet.name') }}
</x-mail::message>
