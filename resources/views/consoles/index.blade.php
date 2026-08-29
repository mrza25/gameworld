<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    My Consoles
                </h1>

                <p class="text-base-content/60">
                    Manage your registered consoles.
                </p>
            </div>

            <a href="{{ route('consoles.create') }}" class="btn btn-primary">
                Add Console
            </a>

        </div>


        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif


        @if ($consoles->count())

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                @foreach ($consoles as $console)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">

                            <div class="flex justify-between">

                                <h2 class="card-title">
                                    {{ $console->type }}
                                </h2>

                                <div class="badge badge-info">
                                    {{ $console->status->value }}
                                </div>

                            </div>


                            <div class="divider"></div>


                            <h3 class="font-semibold mb-2">
                                Games
                            </h3>


                            @if ($console->games->count())
                                <div class="space-y-3">

                                    @foreach ($console->games as $game)
                                        <div class="flex items-center justify-between">

                                            <div>
                                                {{ $game->title }}
                                            </div>

                                            <div class="badge badge-sm">

                                                {{ $game->pivot->status->value }}

                                            </div>

                                        </div>
                                    @endforeach

                                </div>
                            @else
                                <p class="text-sm opacity-60">
                                    No games selected.
                                </p>
                            @endif

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No consoles yet
                </h2>

                <p class="text-base-content/60 mt-2">
                    Register your first console.
                </p>

                <a href="{{ route('consoles.create') }}" class="btn btn-primary mt-4">
                    Add Console
                </a>

            </div>

        @endif

    </div>

</x-layout>
