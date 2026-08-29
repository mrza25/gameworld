<x-layout>

    <div class="py-8 max-w-5xl mx-auto">

        {{-- Header --}}
        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    Console #{{ $console->id }}
                </h1>

                <p class="text-base-content/60 mt-1">
                    Manage console and installed games
                </p>
            </div>

            <a href="{{ route('admin.consoles.index') }}" class="btn btn-outline">
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
        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="card-title mb-4">
                    Console Information
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div>
                        <span class="font-semibold">
                            Customer:
                        </span>

                        {{ $console->user->name }}
                    </div>

                    <div>
                        <span class="font-semibold">
                            Phone:
                        </span>

                        {{ $console->user->phonenumber }}
                    </div>

                    <div>
                        <span class="font-semibold">
                            Console:
                        </span>

                        {{ $console->type }}
                    </div>

                    <div>
                        <span class="font-semibold">
                            Current Status:
                        </span>

                        <span class="badge badge-info">
                            {{ $console->status->value }}
                        </span>
                    </div>

                </div>

            </div>

        </div>


        {{-- Console Status --}}
        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="card-title mb-4">
                    Console Status
                </h2>

                <form
                    action="{{ route('admin.consoles.status.update', $console) }}"
                    method="POST" class="flex gap-3 items-end">

                    @csrf
                    @method('PATCH')

                    <div class="flex-1">

                        <label class="label">
                            <span class="label-text">
                                Status
                            </span>
                        </label>

                        <select name="status" class="select select-bordered w-full">

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

                    </div>

                    <button type="submit" class="btn btn-primary">
                        Update Status
                    </button>

                </form>

            </div>

        </div>


        {{-- Games --}}
        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h2 class="card-title mb-6">
                    Games
                </h2>

                @if ($console->games->count())

                    <div class="space-y-4">

                        @foreach ($console->games as $game)
                            <div class="border rounded-lg p-4">

                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">

                                    {{-- Game Information --}}
                                    <div>

                                        <h3 class="font-bold text-lg">
                                            {{ $game->title }}
                                        </h3>

                                        <p class="text-sm opacity-60">
                                            {{ $game->platform }}
                                            -
                                            {{ $game->genre }}
                                        </p>

                                        <p class="text-sm mt-1">
                                            Price:
                                            {{ number_format($game->price) }}
                                        </p>

                                        @if ($game->pivot->installed_at)
                                            <p class="text-xs opacity-60 mt-1">
                                                Installed:
                                                {{ $game->pivot->installed_at->format('Y-m-d H:i') }}
                                            </p>
                                        @endif

                                    </div>


                                    {{-- Game Status --}}
                                    <form
                                        action="{{ route('admin.consoles.games.status.update', [
                                            'console' => $console,
                                            'game' => $game,
                                        ]) }}"
                                        method="POST" class="flex gap-2">

                                        @csrf
                                        @method('PATCH')

                                        <select name="status" class="select select-bordered select-sm">

                                            <option value="pending" @selected($game->pivot->status->value === 'pending')>
                                                Pending
                                            </option>

                                            <option value="installing" @selected($game->pivot->status->value === 'installing')>
                                                Installing
                                            </option>

                                            <option value="installed" @selected($game->pivot->status->value === 'installed')>
                                                Installed
                                            </option>

                                        </select>

                                        <button type="submit" class="btn btn-sm btn-primary">
                                            Update
                                        </button>

                                    </form>

                                </div>

                            </div>
                        @endforeach

                    </div>
                @else
                    <p class="text-center opacity-60 py-8">
                        No games have been added to this console.
                    </p>

                @endif

            </div>

        </div>

    </div>

</x-layout>
