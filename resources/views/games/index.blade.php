<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold">Games</h1>
                <p class="text-base-content/60">
                    Browse all available games
                </p>
            </div>

            <a href="{{ route('games.create') }}" class="btn btn-primary">
                Add Game
            </a>
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if ($games->count())

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                @foreach ($games as $game)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body"
                            Style="border: 1px solid #244077; display: flex; flex-direction: column; justify-content: center; height: 100%;
                            alaign-items: center; text-align: center; background-color: #244077; border-radius: 0.5rem; padding: 1rem; background-position: center;">

                            <h2 class="card-title">
                                {{ $game->title }}
                            </h2>

                            <div class="space-y-2 text-sm">

                                <p>
                                    <strong>Genre:</strong>
                                    {{ $game->genre }}
                                </p>

                                <p>
                                    <strong>Platform:</strong>
                                    {{ $game->platform }}
                                </p>

                                <p>
                                    <strong>Size:</strong>
                                    {{ $game->size }} GB
                                </p>

                                <p>
                                    <strong>Price:</strong>
                                    {{ number_format($game->price) }}
                                </p>

                            </div>

                            <div class="card-actions justify-end mt-4">

                                <a href="{{ route('games.show', $game) }}" class="btn btn-sm btn-primary">
                                    View
                                </a>

                                <a href="{{ route('games.edit', $game) }}" class="btn btn-sm btn-outline">
                                    Edit
                                </a>

                                <form action="{{ route('games.destroy', $game) }}" method="POST">
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-error">
                                        Delete
                                    </button>
                                </form>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No games found
                </h2>

                <p class="text-base-content/60 mt-2">
                    There are no games available yet.
                </p>

                <a href="{{ route('games.create') }}" class="btn btn-primary mt-4">
                    Add First Game
                </a>

            </div>

        @endif

    </div>

</x-layout>
