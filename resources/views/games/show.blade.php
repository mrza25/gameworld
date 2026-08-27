<x-layout>

    <div class="py-8 max-w-2xl mx-auto">

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h1 class="card-title text-3xl">
                    {{ $game->title }}
                </h1>

                <div class="divider"></div>

                <div class="space-y-4">

                    <div>
                        <span class="font-semibold">
                            Genre:
                        </span>

                        {{ $game->genre }}
                    </div>

                    <div>
                        <span class="font-semibold">
                            Platform:
                        </span>

                        {{ $game->platform }}
                    </div>

                    <div>
                        <span class="font-semibold">
                            Size:
                        </span>

                        {{ $game->size }} GB
                    </div>

                </div>

                <div class="card-actions justify-end mt-6">

                    <a href="{{ route('games.index') }}" class="btn">
                        Back
                    </a>

                    <a href="{{ route('games.edit', $game) }}" class="btn btn-primary">
                        Edit
                    </a>

                </div>

            </div>

        </div>

    </div>

</x-layout>
