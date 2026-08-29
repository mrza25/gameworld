<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        <h1 class="text-3xl font-bold mb-2">
            Add Console
        </h1>

        <p class="text-base-content/60 mb-8">
            Register your console and select the games you want.
        </p>

        @if ($errors->any())

            <div class="alert alert-error mb-6">

                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>

            </div>

        @endif

        <form action="{{ route('consoles.store') }}" method="POST" class="space-y-6">

            @csrf

            <x-form.field name="type" label="Console Type" placeholder="PS5" required />

            <div>

                <label class="label">

                    <span class="label-text">
                        Games
                    </span>

                </label>

                <div class="grid md:grid-cols-2 gap-3">

                    @foreach ($games as $game)
                        <label class="flex items-center gap-3 border rounded-lg p-4 cursor-pointer">

                            <input type="checkbox" name="games[]" value="{{ $game->id }}" class="checkbox"
                                @checked(in_array($game->id, old('games', [])))>

                            <div>

                                <div class="font-semibold">
                                    {{ $game->title }}
                                </div>

                                <div class="text-sm opacity-60">
                                    {{ $game->platform }}
                                    -
                                    {{ number_format($game->price) }}
                                </div>

                            </div>

                        </label>
                    @endforeach

                </div>

            </div>

            <button type="submit" class="btn btn-primary w-full">
                Add Console
            </button>

        </form>

    </div>

</x-layout>
