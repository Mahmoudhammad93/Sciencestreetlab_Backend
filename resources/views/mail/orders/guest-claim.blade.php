<x-mail::message>
# {{ __('Activate your course') }}

{{ __('Hello') }} {{ $customerName }},

{{ __('Your course purchase was successful.') }}

**{{ __('Order') }}:** {{ $order->order_number }}

{{ __('To activate the course and access the content, use the activation link below.') }}

{{-- Button label localized via mailable --}}
<x-mail::button :url="$claimUrl">
{{ $activateLabel ?? __('Activate course') }}
</x-mail::button>

{{ __('This link expires in 72 hours.') }}

{{ __('Thanks') }},<br>
{{ config('app.name') }}
</x-mail::message>
