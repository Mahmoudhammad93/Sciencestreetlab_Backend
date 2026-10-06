<x-mail::message>
# {{ __('mail.order_delivered_heading') }}

{{ __('mail.hello') }} {{ $customerName }},

{{ __('mail.order_delivered_body', ['order' => $order->order_number]) }}

@if (! empty($courseNames))
{{ __('mail.order_delivered_courses_intro') }}

@foreach ($courseNames as $courseName)
- **{{ $courseName }}**
@endforeach
@endif

@if ($accountExists && $loginUrl)
<x-mail::button :url="$loginUrl">
{{ $primaryCtaLabel }}
</x-mail::button>

@if ($forgotPasswordUrl)
[{{ $forgotLabel }}]({{ $forgotPasswordUrl }})
@endif
@elseif (! $accountExists && $activationUrl)
<x-mail::button :url="$activationUrl">
{{ $primaryCtaLabel }}
</x-mail::button>
@endif

**{{ __('mail.order_number_label') }}:** {{ $order->order_number }}

@if (! empty($enrollmentQrs))
# Course enrollment verification

@foreach ($enrollmentQrs as $enrollmentQr)
**{{ $enrollmentQr['course_name'] }}**

{{ __('Scan this QR code to verify your course enrollment.') }}

@if (isset($message) && ! $message instanceof \Illuminate\Mail\TextMessage)
<img src="{{ $message->embedData($enrollmentQr['qr_png'], $enrollmentQr['filename'], 'image/png') }}" alt="Enrollment verification QR" width="180" height="180">
@endif

{{ $enrollmentQr['verification_url'] }}

@endforeach
@endif

{{ __('mail.thanks') }},<br>
{{ config('sciencestreet.name', config('app.name')) }}
</x-mail::message>
