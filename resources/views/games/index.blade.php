<x-layout>

    <div class="py-8">
        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    Games
                </h1>

                <p class="text-base-content/60 mt-1">
                    Browse available games
                </p>
            </div>

            @auth
                @if (auth()->user()->is_admin)
                    <a href="{{ route('admin.games.create') }}" class="btn btn-primary">
                        Add Game
                    </a>
                @endif
            @endauth

        </div>


        @if ($games->isEmpty())

            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No games available
                </h2>

                <p class="text-base-content/60 mt-2">
                    There are currently no games in the catalog.
                </p>

            </div>
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">

                @foreach ($games as $game)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">

                            <h2 class="card-title">
                                {{ $game->title }}
                            </h2>

                            <div class="space-y-1 text-sm text-base-content/70">

                                <p>
                                    Platform:
                                    <span class="font-medium">
                                        {{ $game->platform }}
                                    </span>
                                </p>

                                <p>
                                    Genre:
                                    <span class="font-medium">
                                        {{ $game->genre }}
                                    </span>
                                </p>

                                <p>
                                    Size:
                                    <span class="font-medium">
                                        {{ $game->size }}
                                    </span>
                                </p>

                            </div>

                            <div class="mt-3 text-lg font-bold">
                                {{ $game->price }}
                            </div>

                            <div class="card-actions justify-end mt-4">

                                <a href="{{ route('games.show', $game) }}" class="btn btn-sm btn-outline">
                                    View
                                </a>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>

        @endif

    </div>

</x-layout>
