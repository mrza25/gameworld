<x-layout>

    <div class="py-8 max-w-5xl mx-auto">

        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    {{ $console->type }}
                </h1>

                <p class="text-base-content/60">
                    {{ $console->user->name }}
                </p>
            </div>

            <a href="{{ route('admin.consoles.index') }}" class="btn btn-outline">
                Back
            </a>

        </div>


        {{-- Customer Information --}}

        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Customer
                </h2>

                <p>
                    <strong>Name:</strong>
                    {{ $console->user->name }}
                </p>

                <p>
                    <strong>Phone:</strong>
                    {{ $console->user->phonenumber }}
                </p>

            </div>

        </div>


        {{-- Console Information --}}

        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Console
                </h2>

                <p>
                    <strong>Type:</strong>
                    {{ $console->type }}
                </p>

                <p>
                    <strong>Status:</strong>

                    <span class="badge badge-primary">
                        {{ $console->status->value }}
                    </span>
                </p>

            </div>

        </div>


        {{-- Status Management --}}

        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Update Status
                </h2>

                <form action="{{ route('consoles.status.update', $console) }}" method="POST" class="flex gap-4">

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
                        Update
                    </button>

                </form>

            </div>

        </div>


        {{-- Games --}}

        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h2 class="text-xl font-bold mb-4">
                    Games
                </h2>

                @if ($console->games->count())

                    <div class="space-y-3">

                        @foreach ($console->games as $game)
                            <div class="border border-base-300 rounded-lg p-4">

                                <h3 class="font-bold">
                                    {{ $game->title }}
                                </h3>

                                <p class="text-sm text-base-content/60">
                                    {{ $game->genre }}
                                    ·
                                    {{ $game->size }} GB
                                </p>

                            </div>
                        @endforeach

                    </div>
                @else
                    <p class="text-base-content/60">
                        No games added to this console.
                    </p>

                @endif

            </div>

        </div>

    </div>

</x-layout>
