@php
    /** @var \App\Filament\Pages\ManageSalesChannels $this */
    $totals = $this->totals;
    $channels = $this->channels;
    $activity = $this->activity;
    $manage = $this->managePanel;
    $isRtl = app()->getLocale() === 'ar';
    $noneConnected = (int) ($totals['connected'] ?? 0) === 0;
@endphp

<x-filament-panels::page>
    <style>
        .sc-page {
            --sc-ink: #1a1a2e;
            --sc-muted: #64748b;
            --sc-line: rgba(148, 163, 184, 0.28);
            --sc-surface: #ffffff;
            --sc-soft: #f8fafc;
            --sc-navy: #2828a0;
            --sc-navy-soft: rgba(40, 40, 160, 0.08);
            --sc-amber: #b45309;
            --sc-amber-soft: rgba(245, 158, 11, 0.12);
            --sc-green: #047857;
            --sc-green-soft: rgba(16, 185, 129, 0.12);
        }
        .dark .sc-page {
            --sc-ink: #f8fafc;
            --sc-muted: #94a3b8;
            --sc-line: rgba(148, 163, 184, 0.18);
            --sc-surface: rgba(15, 23, 42, 0.55);
            --sc-soft: rgba(30, 41, 59, 0.65);
            --sc-navy-soft: rgba(99, 102, 241, 0.16);
            --sc-amber-soft: rgba(245, 158, 11, 0.16);
            --sc-green-soft: rgba(16, 185, 129, 0.16);
        }
        .sc-page { color: var(--sc-ink); }
        .sc-hero {
            position: relative;
            overflow: hidden;
            border-radius: 1.25rem;
            border: 1px solid var(--sc-line);
            background:
                radial-gradient(120% 80% at 100% 0%, rgba(252, 213, 0, 0.14), transparent 55%),
                radial-gradient(90% 70% at 0% 100%, var(--sc-navy-soft), transparent 50%),
                var(--sc-surface);
            padding: 1.35rem 1.5rem;
        }
        .sc-hero h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .sc-hero p {
            margin: 0.4rem 0 0;
            max-width: 42rem;
            color: var(--sc-muted);
            font-size: 0.925rem;
            line-height: 1.55;
        }
        .sc-kpis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.85rem;
        }
        @media (max-width: 640px) {
            .sc-kpis { grid-template-columns: 1fr; }
        }
        .sc-kpi {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            border-radius: 1rem;
            border: 1px solid var(--sc-line);
            background: var(--sc-surface);
            padding: 1rem 1.1rem;
            min-height: 5rem;
        }
        .sc-kpi__icon {
            display: grid;
            place-items: center;
            width: 2.6rem;
            height: 2.6rem;
            border-radius: 0.85rem;
            flex-shrink: 0;
            font-size: 1.05rem;
        }
        .sc-kpi__icon.is-connected { background: var(--sc-green-soft); color: var(--sc-green); }
        .sc-kpi__icon.is-synced { background: var(--sc-navy-soft); color: var(--sc-navy); }
        .sc-kpi__icon.is-attention { background: var(--sc-amber-soft); color: var(--sc-amber); }
        .dark .sc-kpi__icon.is-synced { color: #a5b4fc; }
        .sc-kpi__label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--sc-muted);
            letter-spacing: 0.01em;
        }
        .sc-kpi__value {
            margin-top: 0.15rem;
            font-size: 1.65rem;
            font-weight: 750;
            line-height: 1;
            letter-spacing: -0.03em;
        }
        .sc-section-title {
            margin: 0 0 0.85rem;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--sc-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .sc-guide {
            display: flex;
            gap: 0.9rem;
            align-items: flex-start;
            border-radius: 1rem;
            border: 1px dashed rgba(40, 40, 160, 0.35);
            background: var(--sc-navy-soft);
            padding: 1rem 1.15rem;
            margin-bottom: 1rem;
        }
        .sc-guide__mark {
            display: grid;
            place-items: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 999px;
            background: #fcd500;
            color: #1a1a2e;
            font-weight: 800;
            flex-shrink: 0;
        }
        .sc-guide strong { display: block; font-size: 0.95rem; margin-bottom: 0.2rem; }
        .sc-guide span { color: var(--sc-muted); font-size: 0.875rem; line-height: 1.5; }
        .sc-channels { display: grid; gap: 0.85rem; }
        .sc-channel {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 1rem;
            align-items: center;
            border-radius: 1.15rem;
            border: 1px solid var(--sc-line);
            background: var(--sc-surface);
            padding: 1.1rem 1.2rem;
            transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }
        .sc-channel:hover {
            border-color: rgba(40, 40, 160, 0.35);
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        }
        @media (max-width: 720px) {
            .sc-channel {
                grid-template-columns: auto 1fr;
                grid-template-areas:
                    "logo body"
                    "actions actions";
            }
            .sc-channel__logo { grid-area: logo; }
            .sc-channel__body { grid-area: body; }
            .sc-channel__actions { grid-area: actions; justify-content: stretch; }
            .sc-channel__actions > * { flex: 1; }
        }
        .sc-channel__logo {
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 1rem;
            display: grid;
            place-items: center;
            background: var(--sc-soft);
            border: 1px solid var(--sc-line);
            overflow: hidden;
            flex-shrink: 0;
        }
        .sc-channel__logo svg { width: 1.55rem; height: 1.55rem; }
        .sc-channel__logo.is-google { background: linear-gradient(145deg, #fff 40%, #e8f0fe); }
        .sc-channel__logo.is-youtube { background: linear-gradient(145deg, #fff 35%, #ffe4e4); }
        .dark .sc-channel__logo.is-google { background: linear-gradient(145deg, #1e293b, #172554); }
        .dark .sc-channel__logo.is-youtube { background: linear-gradient(145deg, #1e293b, #450a0a); }
        .sc-channel__name {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -0.015em;
        }
        .sc-channel__meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem;
            margin-top: 0.35rem;
        }
        .sc-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.2rem 0.65rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
            line-height: 1.3;
        }
        .sc-badge .dot {
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 999px;
            background: currentColor;
        }
        .sc-badge.is-connected { background: var(--sc-green-soft); color: var(--sc-green); border-color: rgba(4, 120, 87, 0.18); }
        .sc-badge.is-expired,
        .sc-badge.is-attention { background: var(--sc-amber-soft); color: var(--sc-amber); border-color: rgba(180, 83, 9, 0.2); }
        .sc-badge.is-idle { background: var(--sc-soft); color: var(--sc-muted); border-color: var(--sc-line); }
        .sc-channel__summary {
            margin: 0.55rem 0 0;
            color: var(--sc-muted);
            font-size: 0.875rem;
            line-height: 1.45;
        }
        .sc-channel__sync {
            margin: 0.35rem 0 0;
            color: var(--sc-muted);
            font-size: 0.75rem;
        }
        .sc-channel__issue {
            margin-top: 0.7rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(180, 83, 9, 0.25);
            background: var(--sc-amber-soft);
            padding: 0.7rem 0.85rem;
            font-size: 0.84rem;
        }
        .sc-channel__issue strong { display: block; margin-bottom: 0.15rem; }
        .sc-channel__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
        }
        .sc-activity {
            border-radius: 1.15rem;
            border: 1px solid var(--sc-line);
            background: var(--sc-surface);
            padding: 1.15rem 1.25rem;
        }
        .sc-activity__title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .sc-activity__group {
            margin-top: 1rem;
        }
        .sc-activity__group-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--sc-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.55rem;
        }
        .sc-activity__item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 0.7rem;
            align-items: start;
            padding: 0.65rem 0;
            border-top: 1px solid var(--sc-line);
            font-size: 0.875rem;
        }
        .sc-activity__item:first-child { border-top: 0; padding-top: 0; }
        .sc-activity__icon {
            width: 1.7rem;
            height: 1.7rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .sc-activity__icon.is-success { background: var(--sc-green-soft); color: var(--sc-green); }
        .sc-activity__icon.is-warning { background: var(--sc-amber-soft); color: var(--sc-amber); }
        .sc-activity__icon.is-error { background: rgba(239, 68, 68, 0.12); color: #b91c1c; }
        .sc-activity__icon.is-info { background: var(--sc-navy-soft); color: var(--sc-navy); }
        .dark .sc-activity__icon.is-info { color: #a5b4fc; }
        .sc-activity__time { color: var(--sc-muted); font-size: 0.75rem; white-space: nowrap; }
        .sc-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(2, 6, 23, 0.55);
            backdrop-filter: blur(4px);
        }
        .sc-modal {
            width: min(100%, 32rem);
            border-radius: 1.25rem;
            border: 1px solid var(--sc-line);
            background: var(--sc-surface);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.28);
            padding: 1.35rem 1.4rem;
        }
        .sc-modal h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 750;
        }
        .sc-steps { list-style: none; margin: 1.1rem 0 0; padding: 0; display: grid; gap: 0.45rem; }
        .sc-steps li {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.875rem;
            color: var(--sc-muted);
            padding: 0.45rem 0.55rem;
            border-radius: 0.7rem;
        }
        .sc-steps li.is-done { color: var(--sc-green); background: var(--sc-green-soft); }
        .sc-steps li.is-current { color: var(--sc-ink); background: var(--sc-navy-soft); font-weight: 700; }
        .sc-steps .mark {
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 999px;
            display: grid;
            place-items: center;
            font-size: 0.7rem;
            font-weight: 800;
            border: 1px solid currentColor;
            flex-shrink: 0;
        }
        .sc-modal-note {
            margin-top: 0.9rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(180, 83, 9, 0.25);
            background: var(--sc-amber-soft);
            color: var(--sc-amber);
            padding: 0.75rem 0.85rem;
            font-size: 0.85rem;
            line-height: 1.45;
        }
        .sc-modal-body { margin-top: 0.85rem; color: var(--sc-muted); font-size: 0.9rem; line-height: 1.5; }
        .sc-modal-actions {
            margin-top: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.5rem;
        }
    </style>

    <div class="sc-page space-y-6" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
        <div class="sc-hero">
            <h2>{{ __('sales_channels.title') }}</h2>
            <p>{{ __('sales_channels.subtitle') }}</p>
        </div>

        <div class="sc-kpis">
            <div class="sc-kpi">
                <div class="sc-kpi__icon is-connected" aria-hidden="true">✓</div>
                <div>
                    <div class="sc-kpi__label">{{ __('sales_channels.kpi.connected') }}</div>
                    <div class="sc-kpi__value">{{ $totals['connected'] }}</div>
                </div>
            </div>
            <div class="sc-kpi">
                <div class="sc-kpi__icon is-synced" aria-hidden="true">↻</div>
                <div>
                    <div class="sc-kpi__label">{{ __('sales_channels.kpi.synced') }}</div>
                    <div class="sc-kpi__value">{{ $totals['synced'] }}</div>
                </div>
            </div>
            <div class="sc-kpi">
                <div class="sc-kpi__icon is-attention" aria-hidden="true">!</div>
                <div>
                    <div class="sc-kpi__label">{{ __('sales_channels.kpi.needs_attention') }}</div>
                    <div class="sc-kpi__value">{{ $totals['needs_attention'] }}</div>
                </div>
            </div>
        </div>

        <section>
            <h3 class="sc-section-title">{{ __('sales_channels.channels_heading') }}</h3>

            @if ($noneConnected)
                <div class="sc-guide">
                    <div class="sc-guide__mark" aria-hidden="true">1</div>
                    <div>
                        <strong>{{ __('sales_channels.empty_guide.title') }}</strong>
                        <span>{{ __('sales_channels.empty_guide.body') }}</span>
                    </div>
                </div>
            @endif

            <div class="sc-channels">
                @foreach ($channels as $channel)
                    @php
                        $platform = $channel['platform'] ?? '';
                        $cardState = $channel['card_state'] ?? '';
                        $statusClass = $channel['badge_class'] ?? 'is-idle';
                        $isGoogle = $platform === 'google_merchant';
                        $isYoutube = $platform === 'youtube_shopping';
                    @endphp
                    <article class="sc-channel">
                        <div class="sc-channel__logo {{ $isYoutube ? 'is-youtube' : 'is-google' }}" aria-hidden="true">
                            @if ($isYoutube)
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="2" y="5" width="20" height="14" rx="4" fill="#FF0000"/>
                                    <path d="M10 9.5v5l5-2.5-5-2.5Z" fill="#fff"/>
                                </svg>
                            @else
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2l2.1 6.4H21l-5.2 3.8 2 6.3L12 14.9 6.2 18.5l2-6.3L3 8.4h6.9L12 2z" fill="#FBBC04"/>
                                    <circle cx="12" cy="12" r="4.2" fill="#4285F4"/>
                                    <circle cx="12" cy="12" r="2.1" fill="#fff"/>
                                </svg>
                            @endif
                        </div>

                        <div class="sc-channel__body">
                            <h2 class="sc-channel__name">{{ $channel['name'] }}</h2>
                            <div class="sc-channel__meta">
                                <span class="sc-badge {{ $statusClass }}">
                                    @if (in_array($cardState, ['setup_required', 'needs_attention'], true))
                                        <span aria-hidden="true">⚠</span>
                                    @elseif ($cardState === 'connected' || $cardState === 'eligibility_ready')
                                        <span aria-hidden="true">✓</span>
                                    @else
                                        <span class="dot" aria-hidden="true"></span>
                                    @endif
                                    {{ $channel['connection_label'] }}
                                </span>
                            </div>
                            <p class="sc-channel__summary">{{ $channel['summary'] }}</p>
                            @if ($isGoogle && $cardState === 'connected')
                                @if (! empty($channel['merchant_label']))
                                    <p class="sc-channel__sync">{{ __('sales_channels.setup.test_success_body', ['name' => $channel['merchant_label'], 'merchant' => $channel['masked_merchant_id'] ?? '']) }}</p>
                                @endif
                                <p class="sc-channel__sync">{{ __('sales_channels.summary.synced', ['count' => $channel['products_synced']]) }}</p>
                                <p class="sc-channel__sync">{{ __('sales_channels.last_sync', ['time' => $channel['last_synced_human']]) }}</p>
                            @endif
                        </div>

                        <div class="sc-channel__actions">
                            @if ($isGoogle)
                                @if ($cardState === 'setup_required')
                                    @if ($channel['can_setup'])
                                        <x-filament::button color="primary" wire:click="openGoogleSetup">
                                            {{ __('sales_channels.actions.setup_channel') }}
                                        </x-filament::button>
                                    @endif
                                @elseif ($cardState === 'ready_to_connect')
                                    @if ($channel['can_setup'])
                                        <x-filament::button color="primary" wire:click="openGoogleSetup">
                                            {{ __('sales_channels.actions.test_and_connect') }}
                                        </x-filament::button>
                                    @endif
                                @elseif ($cardState === 'needs_attention')
                                    @if ($channel['can_setup'])
                                        <x-filament::button color="warning" wire:click="openGoogleSetup">
                                            {{ __('sales_channels.actions.fix_connection') }}
                                        </x-filament::button>
                                    @endif
                                    @if ($channel['can_manage'])
                                        <x-filament::button color="gray" wire:click="openManage({{ $channel['id'] }})">
                                            {{ __('sales_channels.actions.manage') }}
                                        </x-filament::button>
                                    @endif
                                @else
                                    @if (($channel['products_needing_attention'] ?? 0) > 0)
                                        <x-filament::button color="warning" wire:click="openManage({{ $channel['id'] }})">
                                            {{ __('sales_channels.actions.fix_issues') }}
                                        </x-filament::button>
                                    @endif
                                    <x-filament::button color="gray" wire:click="openManage({{ $channel['id'] }})" :disabled="! $channel['can_manage']">
                                        {{ __('sales_channels.actions.manage') }}
                                    </x-filament::button>
                                    <span @if (! empty($channel['sync_disabled_reason'])) title="{{ $channel['sync_disabled_reason'] }}" @endif>
                                        <x-filament::button color="primary" wire:click="syncNow({{ $channel['id'] }})" :disabled="! $channel['can_sync']">
                                            {{ __('sales_channels.actions.sync_now') }}
                                        </x-filament::button>
                                    </span>
                                @endif
                            @elseif ($isYoutube)
                                <x-filament::button color="gray" wire:click="openYoutubeGuide">
                                    {{ __('sales_channels.actions.view_setup_instructions') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="sc-activity">
            <h3 class="sc-activity__title">{{ __('sales_channels.activity.title') }}</h3>
            @if ($activity->isEmpty())
                <p class="mt-3 text-sm" style="color: var(--sc-muted)">{{ __('sales_channels.activity.empty') }}</p>
            @else
                @foreach ($activity->groupBy('group') as $group => $items)
                    <div class="sc-activity__group">
                        <div class="sc-activity__group-label">{{ $group }}</div>
                        @foreach ($items as $item)
                            <div class="sc-activity__item">
                                <div class="sc-activity__icon is-{{ $item['level'] === 'success' ? 'success' : ($item['level'] === 'warning' ? 'warning' : ($item['level'] === 'error' ? 'error' : 'info')) }}" aria-hidden="true">
                                    @if ($item['level'] === 'success') ✓
                                    @elseif ($item['level'] === 'warning') !
                                    @elseif ($item['level'] === 'error') ✕
                                    @else ●
                                    @endif
                                </div>
                                <div>{{ $item['message'] }}</div>
                                <div class="sc-activity__time">{{ $item['when'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </section>

        @if ($this->showGoogleSetup && $this->canConfigureGoogle())
            @php
                $step = $this->googleSetupStep;
                $testOk = (bool) ($this->setupTestResult['ok'] ?? false);
                $testIssue = $this->setupTestResult['issue'] ?? null;
                $readiness = $this->setupReadiness ?? ['ready' => 0, 'needs_attention' => 0, 'total' => 0];
            @endphp
            <div class="sc-modal-backdrop" role="dialog" aria-modal="true">
                <div class="sc-modal" style="width:min(100%,36rem)">
                    <h2>{{ __('sales_channels.setup.title') }}</h2>
                    <ol class="sc-steps">
                        @foreach ([1, 2, 3, 4, 5] as $s)
                            <li class="{{ $step > $s ? 'is-done' : ($step === $s ? 'is-current' : '') }}">
                                <span class="mark" aria-hidden="true">
                                    @if ($step > $s) ✓
                                    @else {{ $s }}
                                    @endif
                                </span>
                                {{ __('sales_channels.setup.step'.$s) }}
                            </li>
                        @endforeach
                    </ol>

                    @if ($step === 1)
                        <div class="mt-4 space-y-2">
                            <label class="text-xs font-semibold" style="color: var(--sc-muted)">{{ __('sales_channels.setup.merchant_id_label') }}</label>
                            <input type="text" wire:model="setupMerchantId" inputmode="numeric" autocomplete="off" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                            <p class="text-sm" style="color: var(--sc-muted)">{{ __('sales_channels.setup.merchant_id_help') }}</p>
                        </div>
                    @elseif ($step === 2)
                        <p class="sc-modal-body">{{ __('sales_channels.setup.service_account_steps') }}</p>
                        <div class="mt-3 space-y-2">
                            <label class="text-xs font-semibold" style="color: var(--sc-muted)">{{ __('sales_channels.setup.service_account_label') }}</label>
                            <textarea wire:model="setupServiceAccountJson" rows="8" autocomplete="off" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800" placeholder='{"type":"service_account",...}'></textarea>
                            <p class="text-sm" style="color: var(--sc-muted)">{{ __('sales_channels.setup.service_account_help') }}</p>
                            <p class="text-sm" style="color: var(--sc-muted)">{{ __('sales_channels.setup.service_account_saved') }}</p>
                        </div>
                    @elseif ($step === 3)
                        @if ($this->setupTestResult === null)
                            <p class="sc-modal-body">{{ __('sales_channels.setup.step3') }}</p>
                            <div class="sc-modal-actions" style="justify-content:flex-start">
                                <x-filament::button color="primary" wire:click="runGoogleConnectionTest" wire:loading.attr="disabled">
                                    {{ __('sales_channels.setup.test_button') }}
                                </x-filament::button>
                            </div>
                        @elseif ($testOk)
                            <div class="sc-modal-note" style="border-color: rgba(4,120,87,0.25); background: var(--sc-green-soft); color: var(--sc-green)">
                                <strong>✓ {{ __('sales_channels.setup.test_success_title') }}</strong>
                                <div class="mt-1">
                                    {{ __('sales_channels.setup.test_success_body', [
                                        'name' => $this->setupTestResult['account_name'] ?? 'ScienceStreetLab',
                                        'merchant' => $this->setupTestResult['masked_merchant_id'] ?? '',
                                    ]) }}
                                </div>
                            </div>
                        @else
                            <div class="sc-modal-note">
                                <strong>⚠ {{ __('sales_channels.setup.test_failure_title') }}</strong>
                                @if (is_array($testIssue))
                                    <div class="mt-1">{{ $testIssue['message'] ?? '' }}</div>
                                @endif
                            </div>
                            <div class="sc-modal-actions" style="justify-content:flex-start">
                                <x-filament::button color="warning" wire:click="runGoogleConnectionTest">
                                    {{ __('sales_channels.actions.try_again') }}
                                </x-filament::button>
                            </div>
                        @endif
                    @elseif ($step === 4)
                        <p class="sc-modal-body" style="font-weight:700;color:var(--sc-ink)">{{ __('sales_channels.setup.readiness_title') }}</p>
                        <div class="mt-3 space-y-2">
                            <div class="rounded-xl border p-3" style="border-color: var(--sc-line); background: var(--sc-green-soft)">
                                ✓ {{ __('sales_channels.setup.readiness_ready', ['count' => $readiness['ready']]) }}
                            </div>
                            <div class="rounded-xl border p-3" style="border-color: var(--sc-line); background: var(--sc-amber-soft)">
                                ⚠ {{ __('sales_channels.setup.readiness_attention', ['count' => $readiness['needs_attention']]) }}
                            </div>
                        </div>
                        @if (($readiness['needs_attention'] ?? 0) > 0)
                            <div class="mt-3">
                                <x-filament::button color="gray" wire:click="openGoogleAttentionProducts">
                                    {{ __('sales_channels.actions.view_products_attention') }}
                                </x-filament::button>
                            </div>
                        @endif
                    @elseif ($step === 5)
                        <p class="sc-modal-body" style="font-weight:700;color:var(--sc-ink)">{{ __('sales_channels.setup.activate_intro') }}</p>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li>✓ {{ __('sales_channels.platforms.google_merchant') }} — {{ __('sales_channels.connection.connected') }}</li>
                            <li>{{ __('sales_channels.setup.activate_products') }}: {{ __('sales_channels.setup.readiness_ready', ['count' => $readiness['ready']]) }} · {{ __('sales_channels.setup.readiness_attention', ['count' => $readiness['needs_attention']]) }}</li>
                            <li>{{ __('sales_channels.setup.activate_auto_sync') }}: {{ __('sales_channels.setup.activate_enabled') }}</li>
                        </ul>
                    @endif

                    <div class="sc-modal-actions">
                        <x-filament::button color="gray" wire:click="closeGoogleSetup">{{ __('sales_channels.actions.close') }}</x-filament::button>
                        @if ($step > 1 && $step !== 3)
                            <x-filament::button color="gray" wire:click="googleSetupBack">{{ __('sales_channels.actions.back') }}</x-filament::button>
                        @endif
                        @if ($step === 1 || $step === 2 || $step === 4)
                            <x-filament::button color="primary" wire:click="googleSetupNext">{{ __('sales_channels.actions.next') }}</x-filament::button>
                        @elseif ($step === 3 && $testOk)
                            <x-filament::button color="primary" wire:click="continueAfterGoogleTest">{{ __('sales_channels.actions.continue') }}</x-filament::button>
                        @elseif ($step === 5)
                            <x-filament::button color="primary" wire:click="activateGoogleChannel">{{ __('sales_channels.actions.activate_google') }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($this->showYoutubeGuide)
            @php
                $ytChannel = collect($this->channels)->firstWhere('platform', 'youtube_shopping');
                $checklist = $ytChannel['youtube_checklist'] ?? ['merchant' => false, 'feed' => false, 'eligibility' => false, 'store' => false];
            @endphp
            <div class="sc-modal-backdrop" role="dialog" aria-modal="true">
                <div class="sc-modal">
                    <h2>{{ __('sales_channels.youtube.guide_title') }}</h2>
                    <ul class="mt-4 space-y-2 text-sm">
                        <li>{{ ($checklist['merchant'] ?? false) ? '✓' : '○' }} {{ __('sales_channels.youtube.check_merchant') }}</li>
                        <li>{{ ($checklist['feed'] ?? false) ? '✓' : '○' }} {{ __('sales_channels.youtube.check_feed') }}</li>
                        <li>{{ ($checklist['eligibility'] ?? false) ? '✓' : '○' }} {{ __('sales_channels.youtube.check_eligibility') }}</li>
                        <li>{{ ($checklist['store'] ?? false) ? '✓' : '○' }} {{ __('sales_channels.youtube.check_store') }}</li>
                    </ul>
                    <p class="sc-modal-body">{{ __('sales_channels.youtube.not_direct_api') }}</p>
                    <div class="sc-modal-actions">
                        <x-filament::button color="gray" wire:click="closeYoutubeGuide">{{ __('sales_channels.actions.close') }}</x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        @if ($manage)
            <div class="sc-modal-backdrop" role="dialog" aria-modal="true">
                <div class="sc-modal" style="width:min(100%,36rem)">
                    <h2>{{ __('sales_channels.manage.title', ['channel' => $manage['name']]) }}</h2>
                    <p class="sc-modal-body" style="font-weight:600;color:var(--sc-ink)">{{ __('sales_channels.manage.products_heading') }}</p>
                    @if (empty($manage['issues']))
                        <p class="sc-modal-body">{{ __('sales_channels.manage.no_issues') }}</p>
                    @else
                        <div class="mt-3 space-y-2">
                            @foreach ($manage['issues'] as $issue)
                                <div class="rounded-xl border p-3" style="border-color: var(--sc-line); background: var(--sc-soft)">
                                    <div class="font-semibold">{{ $issue['product_name'] }}</div>
                                    <div class="mt-1 text-sm" style="color: var(--sc-muted)">{{ $issue['message'] }}</div>
                                    @if ($issue['edit_url'])
                                        <a href="{{ $issue['edit_url'] }}" class="mt-2 inline-block text-sm font-semibold text-primary-600 hover:underline">
                                            {{ __('sales_channels.actions.fix_product') }}
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="sc-modal-actions" style="justify-content:space-between">
                        @if ($manage['can_advanced'])
                            <x-filament::button color="gray" wire:click="openAdvanced({{ $manage['id'] }})">{{ __('sales_channels.actions.advanced') }}</x-filament::button>
                        @else
                            <span></span>
                        @endif
                        <x-filament::button color="gray" wire:click="closeManage">{{ __('sales_channels.actions.close') }}</x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        @if ($this->showAdvanced && $this->canViewTechnical())
            <div class="sc-modal-backdrop" style="z-index:60" role="dialog" aria-modal="true">
                <div class="sc-modal">
                    <h2>{{ __('sales_channels.advanced.title') }}</h2>
                    <p class="sc-modal-body">{{ __('sales_channels.advanced.hint') }}</p>
                    <p class="sc-modal-body" style="font-size:0.8rem">{{ __('sales_channels.advanced.credentials_saved') }}</p>
                    <p class="sc-modal-body" style="font-size:0.8rem;margin-top:0.25rem">{{ __('sales_channels.advanced.client_id_hint') }}</p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="text-xs font-semibold" style="color: var(--sc-muted)">{{ __('sales_channels.advanced.external_account_id') }}</label>
                            <input type="text" wire:model="advancedForm.external_account_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                        </div>
                        <div>
                            <label class="text-xs font-semibold" style="color: var(--sc-muted)">{{ __('sales_channels.advanced.external_account_name') }}</label>
                            <input type="text" wire:model="advancedForm.external_account_name" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                        </div>
                    </div>
                    <div class="sc-modal-actions">
                        <x-filament::button color="gray" wire:click="closeAdvanced">{{ __('sales_channels.actions.close') }}</x-filament::button>
                        <x-filament::button color="primary" wire:click="saveAdvanced">{{ __('sales_channels.actions.save_advanced') }}</x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
