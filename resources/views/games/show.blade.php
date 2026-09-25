<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('games.index') }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Games
            </a>

            <h1 class="text-3xl font-bold mt-4">
                {{ $game->title }}
            </h1>

        </div>


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <div class="space-y-4">

                    <div class="flex justify-between">
                        <span class="text-base-content/60">
                            Title
                        </span>

                        <span class="font-semibold">
                            {{ $game->title }}
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-base-content/60">
                            Platform
                        </span>

                        <span class="font-semibold">
                            {{ $game->platform }}
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-base-content/60">
                            Genre
                        </span>

                        <span class="font-semibold">
                            {{ $game->genre }}
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-base-content/60">
                            Size
                        </span>

                        <span class="font-semibold">
                            {{ $game->size }}
                        </span>
                    </div>

                    <div class="divider"></div>

                    <div class="flex justify-between text-xl font-bold">
                        <span>
                            Price
                        </span>

                        <span>
                            {{ $game->price }}
                        </span>
                    </div>

                </div>


                <div class="card-actions justify-end mt-6">

                    @auth
                        <a href="{{ route('orders.create') }}" class="btn btn-primary">
                            Order This Game
                        </a>
                    @endauth

                    @auth
                        @if (auth()->user()->is_admin)
                            <a href="{{ route('admin.games.edit', $game) }}" class="btn btn-outline">
                                Edit
                            </a>
                        @endif
                    @endauth

                </div>

            </div>

        </div>

    </div>

</x-layout>
