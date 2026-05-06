<x-app-layout :hide-footer="true">
    <div class="min-h-screen flex items-center justify-center py-8 px-4"
         x-data="migrationImport({
            initialStatus: @js($status->value),
            statusValues: @js($statusValues),
            startUrl: @js($startUrl),
            statusUrl: @js($statusUrl),
            dashboardUrl: @js($dashboardUrl),
            steps: @js([
                'starting' => __('migration.import_step_starting'),
                'user' => __('migration.import_step_user'),
                'stats' => __('migration.import_step_stats'),
                'trophies' => __('migration.import_step_trophies'),
                'games' => __('migration.import_step_games'),
                'finalizing' => __('migration.import_step_finalizing'),
                'completed' => __('migration.import_completed'),
                'failed' => __('migration.import_failed'),
            ]),
         })"
         x-init="init()">
        <div class="w-full max-w-md text-center">

            {{-- Brand --}}
            <div class="flex justify-center mb-8">
                <x-application-logo class="animate-pulse" />
            </div>

            {{-- Title --}}
            <h1 class="font-heading text-2xl md:text-3xl font-bold uppercase tracking-wide text-text-primary leading-tight mb-2">
                {{ __('migration.import_title') }}
            </h1>

            {{-- PENDING --}}
            <template x-if="status === statusValues.pending">
                <div x-cloak>
                    <p class="text-text-secondary mb-8">{{ __('migration.import_intro') }}</p>
                    <button type="button"
                            @click="start()"
                            class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-accent-blue hover:bg-blue-600 text-white font-semibold rounded-lg transition shadow-lg shadow-accent-blue/20">
                        <span>{{ __('migration.import_cta') }}</span>
                    </button>
                </div>
            </template>

            {{-- IN PROGRESS --}}
            <template x-if="status === statusValues.in_progress">
                <div x-cloak>
                    <p class="text-text-secondary mb-6">{{ __('migration.import_in_progress') }}</p>

                    <div class="flex justify-center mb-6">
                        <svg class="animate-spin h-8 w-8 text-accent-blue" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <div class="max-w-xs mx-auto">
                        <div class="w-full bg-surface-700 rounded-full h-2 overflow-hidden">
                            <div class="bg-accent-blue h-2 rounded-full transition-all duration-500 ease-out"
                                 :style="`width: ${percent}%`"></div>
                        </div>
                        <p class="text-text-tertiary text-xs mt-3" x-text="progressLabel()"></p>
                    </div>
                </div>
            </template>

            {{-- COMPLETED --}}
            <template x-if="status === statusValues.completed">
                <div x-cloak>
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-accent-green/15 flex items-center justify-center">
                            <svg class="w-9 h-9 text-accent-green" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-accent-green font-semibold mb-8">{{ __('migration.import_completed') }}</p>
                    <a :href="dashboardUrl"
                       class="inline-flex items-center justify-center px-6 py-3 bg-accent-green hover:bg-green-600 text-white font-semibold rounded-lg transition shadow-lg shadow-accent-green/20">
                        {{ __('app.continue') }}
                    </a>
                </div>
            </template>

            {{-- FAILED --}}
            <template x-if="status === statusValues.failed">
                <div x-cloak>
                    <div class="flex justify-center mb-6">
                        <div class="w-16 h-16 rounded-full bg-accent-red/15 flex items-center justify-center">
                            <svg class="w-9 h-9 text-accent-red" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-accent-red font-semibold mb-2">{{ __('migration.import_failed') }}</p>
                    <p class="text-xs text-text-muted mb-8" x-text="errorMessage" x-show="errorMessage"></p>
                    <button type="button"
                            @click="start()"
                            class="inline-flex items-center justify-center px-6 py-3 bg-accent-red hover:bg-red-500 text-white font-semibold rounded-lg transition shadow-lg shadow-accent-red/20">
                        {{ __('migration.import_retry') }}
                    </button>
                </div>
            </template>

        </div>
    </div>

    <script>
        function migrationImport(config) {
            return {
                status: config.initialStatus,
                statusValues: config.statusValues,
                startUrl: config.startUrl,
                statusUrl: config.statusUrl,
                dashboardUrl: config.dashboardUrl,
                steps: config.steps,
                percent: 0,
                step: 'starting',
                stepExtra: {},
                errorMessage: '',
                pollHandle: null,

                init() {
                    if (this.status === this.statusValues.in_progress) {
                        this.startPolling();
                    }
                },

                async start() {
                    this.errorMessage = '';
                    try {
                        const response = await fetch(this.startUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                        });
                        const body = await response.json();
                        if (response.status === 202) {
                            this.status = this.statusValues.in_progress;
                            this.percent = 0;
                            this.startPolling();
                        } else if (response.status === 409 && body.status === this.statusValues.completed) {
                            window.location.href = this.dashboardUrl;
                        } else {
                            this.status = body.status || this.statusValues.failed;
                            this.errorMessage = body.message || '';
                        }
                    } catch (e) {
                        this.status = this.statusValues.failed;
                        this.errorMessage = e.message;
                    }
                },

                startPolling() {
                    this.poll();
                    this.pollHandle = setInterval(() => this.poll(), 1500);
                },

                stopPolling() {
                    if (this.pollHandle !== null) {
                        clearInterval(this.pollHandle);
                        this.pollHandle = null;
                    }
                },

                async poll() {
                    try {
                        const response = await fetch(this.statusUrl, {
                            headers: { 'Accept': 'application/json' },
                        });
                        const body = await response.json();
                        this.status = body.status;
                        if (body.progress) {
                            this.percent = body.progress.percent ?? this.percent;
                            this.step = body.progress.step ?? this.step;
                            this.stepExtra = body.progress.extra ?? {};
                            if (body.progress.extra && body.progress.extra.error) {
                                this.errorMessage = body.progress.extra.error;
                            }
                        }
                        if (
                            this.status === this.statusValues.completed
                            || this.status === this.statusValues.failed
                        ) {
                            this.stopPolling();
                        }
                    } catch (e) {
                        // Network blip — keep polling.
                    }
                },

                progressLabel() {
                    let stepLabel = this.steps[this.step] || this.step;
                    if (this.step === 'games' && this.stepExtra.current && this.stepExtra.total) {
                        stepLabel = stepLabel
                            .replace(':current', this.stepExtra.current)
                            .replace(':total', this.stepExtra.total);
                    }
                    return `${this.percent}% — ${stepLabel}`;
                },
            };
        }
    </script>
</x-app-layout>
