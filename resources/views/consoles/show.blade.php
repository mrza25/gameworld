<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('consoles.index') }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Consoles
            </a>

            <h1 class="text-3xl font-bold mt-4">
                {{ $console->type }}
            </h1>

        </div>


        @php
            $statusClass = match ($console->status->value) {
                'pending' => 'badge-warning',
                'processing' => 'badge-info',
                'ready' => 'badge-success',
                'delivered' => 'badge-success',
                default => 'badge-neutral',
            };
        @endphp


        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <div class="flex items-center justify-between mb-5">

                    <h2 class="card-title">
                        Console Information
                    </h2>

                    <span class="badge {{ $statusClass }}">
                        {{ ucfirst($console->status->value) }}
                    </span>

                </div>


                <div class="space-y-4">

                    <div class="flex justify-between">

                        <span class="text-base-content/60">
                            Console
                        </span>

                        <span class="font-semibold">
                            {{ $console->type }}
                        </span>

                    </div>


                    <div class="flex justify-between">

                        <span class="text-base-content/60">
                            Status
                        </span>

                        <span class="font-semibold">
                            {{ ucfirst($console->status->value) }}
                        </span>

                    </div>


                    <div class="flex justify-between">

                        <span class="text-base-content/60">
                            Added
                        </span>

                        <span class="font-semibold">
                            {{ $console->created_at->format('Y-m-d H:i') }}
                        </span>

                    </div>

                </div>

            </div>

        </div>


        {{-- Games --}}

        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h2 class="card-title mb-5">
                    Installed Games
                </h2>


                @if ($console->games->isNotEmpty())

                    <div class="space-y-3">

                        @foreach ($console->games as $game)
                            <div
                                class="flex items-center justify-between border-b border-base-300 pb-3 last:border-0 last:pb-0">

                                <div>

                                    <p class="font-semibold">
                                        {{ $game->title }}
                                    </p>

                                    <p class="text-sm text-base-content/60">
                                        {{ $game->platform }}
                                    </p>

                                </div>


                                @if ($game->pivot->status)
                                    <span class="badge badge-outline">
                                        {{ ucfirst($game->pivot->status) }}
                                    </span>
                                @endif

                            </div>
                        @endforeach

                    </div>
                @else
                    <p class="text-base-content/60">
                        No games have been added to this console.
                    </p>

                @endif

            </div>

        </div>

    </div>

</x-layout>
