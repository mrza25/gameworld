<x-layout>

    <x-slot:title>
        Manage Games
    </x-slot:title>

    <div class="py-8">

        {{-- Header --}}
        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    Manage Games
                </h1>

                <p class="text-base-content/60 mt-1">
                    Manage all games available in Game World.
                </p>
            </div>

            <a href="{{ route('admin.games.create') }}" class="btn btn-primary">
                + Add Game
            </a>

        </div>


        {{-- Success Message --}}
        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif


        {{-- Games Table --}}
        <div class="card bg-base-100 shadow-sm">

            <div class="card-body p-0">

                <div class="overflow-x-auto">

                    <table class="table">

                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Game</th>
                                <th>Genre</th>
                                <th>Platform</th>
                                <th>Size</th>
                                <th>Price</th>
                                <th class="text-right">
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse ($games as $game)
                                <tr>

                                    {{-- ID --}}
                                    <td>
                                        {{ $game->id }}
                                    </td>


                                    {{-- Title --}}
                                    <td>
                                        <div class="font-semibold">
                                            {{ $game->title }}
                                        </div>
                                    </td>


                                    {{-- Genre --}}
                                    <td>
                                        {{ $game->genre }}
                                    </td>


                                    {{-- Platform --}}
                                    <td>
                                        <span class="badge badge-outline">
                                            {{ $game->platform }}
                                        </span>
                                    </td>


                                    {{-- Size --}}
                                    <td>
                                        {{ $game->size }} GB
                                    </td>


                                    {{-- Price --}}
                                    <td>
                                        ${{ number_format($game->price, 2) }}
                                    </td>


                                    {{-- Actions --}}
                                    <td>

                                        <div class="flex justify-end gap-2">

                                            {{-- View --}}
                                            <a href="{{ route('games.show', $game) }}" class="btn btn-sm btn-ghost">
                                                View
                                            </a>


                                            {{-- Edit --}}
                                            <a href="{{ route('admin.games.edit', $game) }}"
                                                class="btn btn-sm btn-outline">
                                                Edit
                                            </a>


                                            {{-- Delete --}}
                                            <form action="{{ route('admin.games.destroy', $game) }}" method="POST"
                                                onsubmit="return confirm('Are you sure you want to delete this game?');">

                                                @csrf
                                                @method('DELETE')

                                                <button type="submit" class="btn btn-sm btn-error btn-outline">
                                                    Delete
                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="7" class="text-center py-12 text-base-content/60">
                                        No games found.
                                    </td>

                                </tr>
                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</x-layout>
