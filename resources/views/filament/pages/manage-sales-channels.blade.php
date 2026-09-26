@php
    /** @var \App\Filament\Pages\ManageSalesChannels $this */
    $totals = $this->totals;
    $channels = $this->channels;
    $activity = $this->activity;
    $wizard = $this->wizardChannel;
    $manage = $this->managePanel;
@endphp

<x-filament-panels::page>
    <div class="sc-wrap space-y-8" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <p class="text-sm text-gray-600 dark:text-gray-300 max-w-2xl">
            {{ __('sales_channels.subtitle') }}
        </p>

        {{-- KPI summary --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="sc-kpi rounded-xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('sales_channels.kpi.connected') }}</div>
                <div class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">{{ $totals['connected'] }}</div>
            </div>
            <div class="sc-kpi rounded-xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('sales_channels.kpi.synced') }}</div>
                <div class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">{{ $totals['synced'] }}</div>
            </div>
            <div class="sc-kpi rounded-xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('sales_channels.kpi.needs_attention') }}</div>
                <div class="mt-1 text-3xl font-semibold text-gray-900 dark:text-white">{{ $totals['needs_attention'] }}</div>
            </div>
        </div>

        {{-- Channel cards --}}
        <div class="space-y-4">
            @foreach ($channels as $channel)
                <article class="sc-card rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $channel['name'] }}</h2>
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium
                                    @if ($channel['connection_status'] === 'connected') border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-200
                                    @elseif ($channel['connection_status'] === 'expired') border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100
                                    @else border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200
                                    @endif">
                                    <span aria-hidden="true">
                                        @if ($channel['connection_status'] === 'connected') ●
                                        @elseif ($channel['connection_status'] === 'expired') ⚠
                                        @else ○
                                        @endif
                                    </span>
                                    <span>{{ $channel['connection_label'] }}</span>
                                </span>
                                @if ($channel['health_status'] === 'needs_attention')
                                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                                        <span aria-hidden="true">⚠</span>
                                        {{ $channel['health_label'] }}
                                    </span>
                                @endif
                            </div>

                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $channel['summary'] }}</p>

                            @if ($channel['connection_status'] === 'connected')
                                <p class="text-xs text-gray-500">
                                    {{ __('sales_channels.last_sync', ['time' => $channel['last_synced_human']]) }}
                                </p>
                            @endif

                            @if (! empty($channel['issue']) && in_array($channel['connection_status'], ['expired', 'not_connected'], true) === false && $channel['health_status'] === 'needs_attention')
                                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50">
                                    <div class="font-medium">{{ $channel['issue']['title'] }}</div>
                                    <div>{{ $channel['issue']['message'] }}</div>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($channel['connection_status'] === 'not_connected' || $channel['connection_status'] === 'disconnected')
                                <x-filament::button
                                    color="primary"
                                    wire:click="openWizard({{ $channel['id'] }})"
                                    :disabled="! $this->canConnect()"
                                >
                                    {{ __('sales_channels.actions.connect') }}
                                </x-filament::button>
                            @elseif ($channel['connection_status'] === 'expired')
                                <x-filament::button
                                    color="warning"
                                    wire:click="reconnect({{ $channel['id'] }})"
                                    :disabled="! $this->canConnect()"
                                >
                                    {{ __('sales_channels.actions.reconnect') }}
                                </x-filament::button>
                            @else
                                @if ($channel['products_needing_attention'] > 0)
                                    <x-filament::button color="warning" wire:click="openManage({{ $channel['id'] }})">
                                        {{ __('sales_channels.actions.fix_issues') }}
                                    </x-filament::button>
                                @endif
                                <x-filament::button color="gray" wire:click="openManage({{ $channel['id'] }})" :disabled="! $channel['can_manage']">
                                    {{ __('sales_channels.actions.manage') }}
                                </x-filament::button>
                                <span
                                    @if ($channel['sync_disabled_reason'])
                                        title="{{ $channel['sync_disabled_reason'] }}"
                                    @endif
                                >
                                    <x-filament::button
                                        color="primary"
                                        wire:click="syncNow({{ $channel['id'] }})"
                                        :disabled="! $channel['can_sync']"
                                    >
                                        {{ __('sales_channels.actions.sync_now') }}
                                    </x-filament::button>
                                </span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- Simplified activity --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('sales_channels.activity.title') }}</h2>
            @if ($activity->isEmpty())
                <p class="mt-3 text-sm text-gray-500">{{ __('sales_channels.activity.empty') }}</p>
            @else
                @php $grouped = $activity->groupBy('group'); @endphp
                <div class="mt-4 space-y-5">
                    @foreach ($grouped as $group => $items)
                        <div>
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $group }}</div>
                            <ul class="space-y-2">
                                @foreach ($items as $item)
                                    <li class="flex items-start justify-between gap-3 text-sm">
                                        <span class="inline-flex items-start gap-2 text-gray-800 dark:text-gray-100">
                                            <span aria-hidden="true">
                                                @if ($item['level'] === 'success') ✓
                                                @elseif ($item['level'] === 'warning') ⚠
                                                @elseif ($item['level'] === 'error') ✕
                                                @else ●
                                                @endif
                                            </span>
                                            {{ $item['message'] }}
                                        </span>
                                        <span class="shrink-0 text-xs text-gray-500">{{ $item['when'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Connect wizard --}}
        @if ($wizard)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
                <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('sales_channels.wizard.title', ['channel' => $wizard['name']]) }}
                    </h2>

                    <ol class="mt-5 space-y-2">
                        @foreach ([1, 2, 3, 4] as $step)
                            <li class="flex items-center gap-2 text-sm
                                @if ($this->wizardStep === $step) font-semibold text-primary-600
                                @elseif ($this->wizardStep > $step) text-emerald-700
                                @else text-gray-400
                                @endif">
                                <span aria-hidden="true">
                                    @if ($this->wizardStep > $step) ✓
                                    @elseif ($this->wizardStep === $step) ●
                                    @else ○
                                    @endif
                                </span>
                                {{ __('sales_channels.wizard.step'.$step) }}
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-4 text-sm text-gray-700 dark:text-gray-200">
                        {{ __('sales_channels.wizard.step'.$this->wizardStep.'_body') }}
                    </p>

                    @unless ($wizard['configured'])
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50">
                            {{ __('sales_channels.wizard.blocked_note') }}
                        </p>
                    @endunless

                    <div class="mt-6 flex flex-wrap justify-end gap-2">
                        <x-filament::button color="gray" wire:click="closeWizard">
                            {{ __('sales_channels.actions.close') }}
                        </x-filament::button>
                        @if ($this->wizardStep > 1)
                            <x-filament::button color="gray" wire:click="wizardBack">
                                {{ __('sales_channels.actions.back') }}
                            </x-filament::button>
                        @endif
                        @if ($this->wizardStep < 4)
                            <x-filament::button color="primary" wire:click="wizardNext">
                                {{ __('sales_channels.actions.next') }}
                            </x-filament::button>
                        @else
                            <x-filament::button color="primary" wire:click="activateChannel">
                                {{ __('sales_channels.actions.activate') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Manage / fix issues panel --}}
        @if ($manage)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
                <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('sales_channels.manage.title', ['channel' => $manage['name']]) }}
                    </h2>

                    <h3 class="mt-4 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        {{ __('sales_channels.manage.products_heading') }}
                    </h3>

                    @if (empty($manage['issues']))
                        <p class="mt-2 text-sm text-gray-500">{{ __('sales_channels.manage.no_issues') }}</p>
                    @else
                        <ul class="mt-3 space-y-3">
                            @foreach ($manage['issues'] as $issue)
                                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $issue['product_name'] }}</div>
                                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $issue['message'] }}</div>
                                    @if ($issue['edit_url'])
                                        <div class="mt-2">
                                            <a href="{{ $issue['edit_url'] }}" class="text-sm font-medium text-primary-600 hover:underline">
                                                {{ __('sales_channels.actions.fix_product') }}
                                            </a>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-6 flex flex-wrap justify-between gap-2">
                        @if ($manage['can_advanced'])
                            <x-filament::button color="gray" wire:click="openAdvanced({{ $manage['id'] }})">
                                {{ __('sales_channels.actions.advanced') }}
                            </x-filament::button>
                        @else
                            <span></span>
                        @endif
                        <x-filament::button color="gray" wire:click="closeManage">
                            {{ __('sales_channels.actions.close') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Advanced settings (collapsed / secondary) --}}
        @if ($this->showAdvanced && $this->canViewTechnical())
            <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('sales_channels.advanced.title') }}</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('sales_channels.advanced.hint') }}</p>
                    <p class="mt-2 text-xs text-gray-500">{{ __('sales_channels.advanced.credentials_saved') }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('sales_channels.advanced.client_id_hint') }}</p>

                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('sales_channels.advanced.external_account_id') }}</label>
                            <input
                                type="text"
                                wire:model="advancedForm.external_account_id"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            >
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('sales_channels.advanced.external_account_name') }}</label>
                            <input
                                type="text"
                                wire:model="advancedForm.external_account_name"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            >
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <x-filament::button color="gray" wire:click="closeAdvanced">
                            {{ __('sales_channels.actions.close') }}
                        </x-filament::button>
                        <x-filament::button color="primary" wire:click="saveAdvanced">
                            {{ __('sales_channels.actions.save_advanced') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
