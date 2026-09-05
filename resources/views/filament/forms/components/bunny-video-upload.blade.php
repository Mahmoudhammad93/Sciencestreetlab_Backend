{{-- resources/views/filament/forms/components/bunny-video-upload.blade.php --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="bunnyVideoUpload({ state: $wire.entangle('{{ $getStatePath() }}') })"
    >
        <input type="file" accept="video/*" x-on:change="handleFileSelect"
               class="block w-full text-sm text-gray-700 border border-gray-300 rounded-lg cursor-pointer">

        <template x-if="uploading">
            <div class="mt-2">
                <div class="h-2 w-full rounded bg-gray-200">
                    <div class="h-2 rounded bg-primary-600" :style="`width:${progress}%`"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500" x-text="progress + '% uploaded'"></p>
            </div>
        </template>

        <template x-if="state && !uploading">
            <p class="mt-2 text-sm text-success-600">
                Uploaded — video ID: <span x-text="state"></span> (processing on Bunny)
            </p>
        </template>

        <template x-if="error">
            <p class="mt-2 text-sm text-danger-600" x-text="error"></p>
        </template>
    </div>
</x-dynamic-component>