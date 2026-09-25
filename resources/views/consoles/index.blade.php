<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-8">

            <div>

                <h1 class="text-3xl font-bold">
                    My Consoles
                </h1>

                <p class="text-base-content/60 mt-1">
                    Your registered consoles.
                </p>

            </div>

        </div>


        @if ($consoles->isNotEmpty())

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">

                @foreach ($consoles as $console)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">

                            <div class="flex items-start justify-between gap-4">

                                <h2 class="card-title">
                                    {{ $console->type }}
                                </h2>

                                @php
                                    $statusClass = match ($console->status->value) {
                                        'pending' => 'badge-warning',
                                        'processing' => 'badge-info',
                                        'ready' => 'badge-success',
                                        'delivered' => 'badge-success',
                                        default => 'badge-neutral',
                                    };
                                @endphp

                                <span class="badge {{ $statusClass }}">
                                    {{ ucfirst($console->status->value) }}
                                </span>

                            </div>


                            <p class="text-sm text-base-content/60 mt-2">

                                Added
                                {{ $console->created_at->format('Y-m-d H:i') }}

                            </p>


                            @if ($console->games->isNotEmpty())
                                <div class="mt-5">

                                    <p class="text-sm font-semibold mb-2">
                                        Games
                                    </p>

                                    <div class="flex flex-wrap gap-2">

                                        @foreach ($console->games as $game)
                                            <span class="badge badge-outline">
                                                {{ $game->title }}
                                            </span>
                                        @endforeach

                                    </div>

                                </div>
                            @else
                                <p class="text-sm text-base-content/60 mt-5">
                                    No games added.
                                </p>
                            @endif


                            <div class="card-actions justify-end mt-5">

                                <a href="{{ route('consoles.show', $console) }}" class="btn btn-sm btn-primary">
                                    View
                                </a>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            <div class="card bg-base-100 shadow-xl">

                <div class="card-body text-center py-16">

                    <h2 class="text-2xl font-bold">
                        No Consoles
                    </h2>

                    <p class="text-base-content/60 mt-2">
                        You don't have any registered consoles.
                    </p>

                    <a href="{{ route('orders.create') }}" class="btn btn-primary mt-5">
                        Create an Order
                    </a>

                </div>

            </div>

        @endif

    </div>

</x-layout>
