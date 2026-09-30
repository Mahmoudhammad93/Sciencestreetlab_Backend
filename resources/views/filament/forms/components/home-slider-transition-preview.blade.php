@php
    /** @var list<array{id:int|string,image_url:?string,background_color:string}> $slides */
    $slides = $slides ?? [];
    $transition = is_string($transition ?? null) ? $transition : 'glitch';
@endphp

<style>
    .ssl-slider-preview {
        position: relative;
        width: 100%;
        height: 220px;
        border-radius: 0.75rem;
        overflow: hidden;
        border: 1px solid rgba(128, 128, 128, 0.25);
        background: #32156a;
    }
    .ssl-slider-preview__viewport {
        position: relative;
        width: 100%;
        height: 100%;
    }
    .ssl-slider-preview__slide {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0.75rem;
        box-sizing: border-box;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        z-index: 1;
        overflow: hidden;
        transform: none;
        filter: none;
        clip-path: none;
    }
    .ssl-slider-preview__slide--active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        z-index: 2;
    }
    .ssl-slider-preview__img {
        max-width: 100%;
        max-height: 100%;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
    }
    .ssl-slider-preview__fx {
        position: absolute;
        inset: 0;
        z-index: 4;
        pointer-events: none;
        opacity: 0;
    }
    .ssl-slider-preview__actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-top: 0.75rem;
    }
    .ssl-slider-preview__hint {
        font-size: 0.8125rem;
        color: rgba(107, 114, 128, 1);
    }

    /* fade */
    .ssl-slider-preview[data-transition='fade'] .ssl-slider-preview__slide {
        transition: opacity 0.7s ease, visibility 0.7s;
    }

    /* glitch — approximate site Water Surface + GlitchNoisePass */
    .ssl-slider-preview[data-transition='glitch'] .ssl-slider-preview__slide {
        transition: opacity 0.45s ease, visibility 0.45s, filter 0.45s ease, transform 1.5s cubic-bezier(0.45, 0, 0.55, 1);
        filter: contrast(1.2) saturate(1.3);
    }
    .ssl-slider-preview[data-transition='glitch']:not(.ssl-slider-preview--transitioning) .ssl-slider-preview__slide--active {
        filter: none;
        transform: none;
    }
    .ssl-slider-preview[data-transition='glitch'].ssl-slider-preview--transitioning .ssl-slider-preview__slide--active {
        animation: ssl-preview-glitch-shake 1.5s cubic-bezier(0.45, 0, 0.55, 1);
    }
    .ssl-slider-preview[data-transition='glitch'] .ssl-slider-preview__fx {
        background:
            repeating-linear-gradient(0deg, rgba(200, 180, 255, 0.35) 0 1px, transparent 1px 2px),
            repeating-linear-gradient(90deg, rgba(0, 0, 0, 0.45) 0 1px, transparent 1px 2px),
            radial-gradient(circle at 50% 40%, rgba(160, 70, 255, 0.45), rgba(20, 0, 40, 0.75));
        mix-blend-mode: screen;
        opacity: 0;
    }
    .ssl-slider-preview[data-transition='glitch'].ssl-slider-preview--transitioning .ssl-slider-preview__fx {
        animation: ssl-preview-glitch-noise 1.5s steps(4, end) forwards;
    }
    @keyframes ssl-preview-glitch-noise {
        0% { opacity: 0; transform: scale(1); }
        8% { opacity: 0.85; transform: translate(-1%, 1%) scale(1.03); }
        22% { opacity: 1; transform: translate(2%, -2%) scale(1.06); }
        40% { opacity: 0.9; transform: translate(-2%, 1%) scale(1.04); }
        55% { opacity: 1; transform: translate(1%, -1%) scale(1.05); }
        75% { opacity: 0.55; transform: translate(-1%, 2%) scale(1.02); }
        100% { opacity: 0; transform: scale(1); }
    }
    @keyframes ssl-preview-glitch-shake {
        0%, 100% { transform: translate(0, 0) skewX(0); filter: none; }
        15% { transform: translate(-10px, 3px) skewX(-2deg); filter: hue-rotate(35deg) contrast(1.5); }
        30% { transform: translate(12px, -4px) skewX(2deg); filter: hue-rotate(-25deg) saturate(1.7); }
        45% { transform: translate(-8px, 2px) skewX(-1deg); filter: contrast(1.9) brightness(1.15); }
        60% { transform: translate(7px, -3px) skewX(1deg); filter: hue-rotate(20deg); }
        80% { transform: translate(-4px, 1px); filter: contrast(1.3); }
    }

    /* slide */
    .ssl-slider-preview[data-transition='slide'] .ssl-slider-preview__slide {
        transition: opacity 0.65s ease, visibility 0.65s, transform 0.65s ease;
        transform: translateX(8%);
    }
    .ssl-slider-preview[data-transition='slide'] .ssl-slider-preview__slide--active {
        transform: translateX(0);
    }

    /* slide_up */
    .ssl-slider-preview[data-transition='slide_up'] .ssl-slider-preview__slide {
        transition: opacity 0.65s ease, visibility 0.65s, transform 0.65s ease;
        transform: translateY(10%);
    }
    .ssl-slider-preview[data-transition='slide_up'] .ssl-slider-preview__slide--active {
        transform: translateY(0);
    }

    /* zoom */
    .ssl-slider-preview[data-transition='zoom'] .ssl-slider-preview__slide {
        transition: opacity 0.7s ease, visibility 0.7s, transform 0.7s ease;
        transform: scale(1.12);
    }
    .ssl-slider-preview[data-transition='zoom'] .ssl-slider-preview__slide--active {
        transform: scale(1);
    }

    /* blur */
    .ssl-slider-preview[data-transition='blur'] .ssl-slider-preview__slide {
        transition: opacity 0.7s ease, visibility 0.7s, filter 0.7s ease, transform 0.7s ease;
        filter: blur(14px);
        transform: scale(1.04);
    }
    .ssl-slider-preview[data-transition='blur'] .ssl-slider-preview__slide--active {
        filter: blur(0);
        transform: scale(1);
    }

    /* flip */
    .ssl-slider-preview[data-transition='flip'] .ssl-slider-preview__viewport {
        perspective: 1200px;
    }
    .ssl-slider-preview[data-transition='flip'] .ssl-slider-preview__slide {
        transition: opacity 0.7s ease, visibility 0.7s, transform 0.7s ease;
        transform: rotateY(75deg) scale(0.92);
    }
    .ssl-slider-preview[data-transition='flip'] .ssl-slider-preview__slide--active {
        transform: rotateY(0) scale(1);
    }

    /* wipe */
    .ssl-slider-preview[data-transition='wipe'] .ssl-slider-preview__slide {
        transition: opacity 0.15s linear, visibility 0.7s, clip-path 0.7s ease;
        clip-path: inset(0 100% 0 0);
        opacity: 1;
        visibility: hidden;
    }
    .ssl-slider-preview[data-transition='wipe'] .ssl-slider-preview__slide--active {
        clip-path: inset(0 0 0 0);
        visibility: visible;
    }

    /* cube */
    .ssl-slider-preview[data-transition='cube'] .ssl-slider-preview__viewport {
        perspective: 1400px;
    }
    .ssl-slider-preview[data-transition='cube'] .ssl-slider-preview__slide {
        transition: opacity 0.7s ease, visibility 0.7s, transform 0.7s ease;
        transform: rotateX(55deg) translateY(8%) scale(0.9);
        transform-origin: center bottom;
    }
    .ssl-slider-preview[data-transition='cube'] .ssl-slider-preview__slide--active {
        transform: rotateX(0) translateY(0) scale(1);
    }

    /* flash */
    .ssl-slider-preview[data-transition='flash'] .ssl-slider-preview__slide {
        transition: opacity 0.45s ease, visibility 0.45s;
    }
    .ssl-slider-preview[data-transition='flash'] .ssl-slider-preview__fx {
        background: #fff;
    }
    .ssl-slider-preview[data-transition='flash'].ssl-slider-preview--transitioning .ssl-slider-preview__fx {
        animation: ssl-preview-flash-burst 0.45s ease-out forwards;
    }
    @keyframes ssl-preview-flash-burst {
        0% { opacity: 0; }
        25% { opacity: 0.85; }
        100% { opacity: 0; }
    }
</style>

@if (count($slides) === 0)
    <p class="ssl-slider-preview__hint">{{ __('admin.settings.fields.home_slider_transition_preview_empty') }}</p>
@else
    <div
        wire:key="ssl-slider-preview-{{ $transition }}"
        x-data="{
            idx: 0,
            transitioning: false,
            timer: null,
            count: {{ count($slides) }},
            play() {
                if (this.count < 2) return;
                this.transitioning = true;
                this.idx = (this.idx + 1) % this.count;
                if (this.timer) clearTimeout(this.timer);
                const ms = '{{ $transition }}' === 'glitch' ? 1500 : 700;
                this.timer = setTimeout(() => { this.transitioning = false; }, ms);
            },
        }"
        x-init="setTimeout(() => play(), 250)"
    >
        <div
            class="ssl-slider-preview"
            data-transition="{{ $transition }}"
            :class="{ 'ssl-slider-preview--transitioning': transitioning }"
            role="img"
            aria-label="{{ __('admin.settings.fields.home_slider_transition_preview') }}"
        >
            <div class="ssl-slider-preview__viewport">
                @foreach ($slides as $i => $slide)
                    <div
                        class="ssl-slider-preview__slide"
                        :class="{ 'ssl-slider-preview__slide--active': idx === {{ $i }} }"
                        style="background-color: {{ $slide['background_color'] }}"
                        x-bind:aria-hidden="idx !== {{ $i }}"
                    >
                        @if (! empty($slide['image_url']))
                            <img
                                class="ssl-slider-preview__img"
                                src="{{ $slide['image_url'] }}"
                                alt=""
                                loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                            />
                        @endif
                    </div>
                @endforeach
                <div class="ssl-slider-preview__fx" aria-hidden="true"></div>
            </div>
        </div>

        <div class="ssl-slider-preview__actions">
            <x-filament::button type="button" color="gray" size="sm" x-on:click="play()">
                {{ __('admin.settings.fields.home_slider_transition_preview_play') }}
            </x-filament::button>
            <span class="ssl-slider-preview__hint">
                {{ __('admin.settings.options.slider_transition.'.$transition) }}
            </span>
        </div>
    </div>
@endif
