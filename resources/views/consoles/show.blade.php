<x-layout>

    <div class="py-8 max-w-5xl mx-auto">

        {{-- Header --}}
        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    {{ $console->type }}
                </h1>

                <p class="text-base-content/60">
                    Console Details
                </p>
            </div>

            <a href="{{ route('consoles.index') }}" class="btn btn-outline">
                Back
            </a>

        </div>


        {{-- Success Message --}}
        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif


        {{-- Console Information --}}
        <div class="card bg-base-100 shadow-xl mb-8">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Console Information
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div>
                        <span class="font-semibold">
                            Type:
                        </span>

                        {{ $console->type }}
                    </div>


                    <div>
                        <span class="font-semibold">
                            Status:
                        </span>

                        <span class="badge badge-primary">
                            {{ $console->status->value }}
                        </span>
                    </div>

                </div>

            </div>

        </div>


        {{-- Admin Status Management --}}
        @if (auth()->user()->is_admin)
            <div class="card bg-base-100 shadow-xl mb-8">

                <div class="card-body">

                    <h2 class="text-xl font-bold mb-4">
                        Update Console Status
                    </h2>

                    <form action="{{ route('consoles.status.update', $console) }}" method="POST"
                        class="flex flex-col md:flex-row gap-4">

                        @csrf
                        @method('PATCH')

                        <select name="status" class="select select-bordered flex-1">

                            <option value="pending" @selected($console->status->value === 'pending')>
                                Pending
                            </option>

                            <option value="installing" @selected($console->status->value === 'installing')>
                                Installing
                            </option>

                            <option value="delivered" @selected($console->status->value === 'delivered')>
                                Delivered
                            </option>

                        </select>

                        <button type="submit" class="btn btn-primary">
                            Update Status
                        </button>

                    </form>

                </div>

            </div>
        @endif


        {{-- Games Installed On Console --}}
        <div class="card bg-base-100 shadow-xl mb-8">

            <div class="card-body">

                <div class="flex items-center justify-between mb-4">

                    <h2 class="text-xl font-bold">
                        Games on this Console
                    </h2>

                    <span class="badge badge-neutral">
                        {{ $console->games->count() }} Games
                    </span>

                </div>


                @if ($console->games->count())

                    <div class="space-y-3">

                        @foreach ($console->games as $game)
                            <div class="flex items-center justify-between border border-base-300 rounded-lg p-4">

                                <div>

                                    <h3 class="font-semibold">
                                        {{ $game->title }}
                                    </h3>

                                    <p class="text-sm text-base-content/60">
                                        {{ $game->genre }}
                                        -
                                        {{ $game->size }} GB
                                    </p>

                                </div>


                                <form action="{{ route('consoles.games.destroy', [$console, $game]) }}" method="POST">

                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="btn btn-sm btn-error">
                                        Remove
                                    </button>

                                </form>

                            </div>
                        @endforeach

                    </div>
                @else
                    <p class="text-base-content/60">
                        No games have been added to this console yet.
                    </p>

                @endif

            </div>

        </div>


        {{-- Add Game --}}
        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Add Game
                </h2>

                @if ($games->count())

                    <form action="{{ route('consoles.games.store', $console) }}" method="POST"
                        class="flex flex-col md:flex-row gap-4">

                        @csrf

                        <select name="game_id" class="select select-bordered flex-1" required>

                            <option value="" disabled selected>
                                Select a game
                            </option>

                            @foreach ($games as $game)
                                <option value="{{ $game->id }}">
                                    {{ $game->title }}
                                    -
                                    {{ $game->platform }}
                                </option>
                            @endforeach

                        </select>


                        <button type="submit" class="btn btn-primary">
                            Add Game
                        </button>

                    </form>
                @else
                    <p class="text-base-content/60">
                        No games are available in the game catalog yet.
                    </p>

                @endif

            </div>

        </div>

    </div>

</x-layout>
