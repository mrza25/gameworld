<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        <h1 class="text-3xl font-bold mb-2">
            Create Order
        </h1>

        <p class="text-base-content/60 mb-8">
            Select your console and the games you want.
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

        <form action="{{ route('orders.store') }}" method="POST" class="space-y-6">

            @csrf

            {{-- Console --}}

            <div>

                <label for="console_id" class="label">
                    <span class="label-text">
                        Select Console
                    </span>
                </label>

                <select name="console_id" id="console_id" class="select select-bordered w-full" required>

                    <option value="">
                        Select your console
                    </option>

                    @foreach ($consoles as $console)
                        <option value="{{ $console->id }}" @selected(old('console_id') == $console->id)>
                            {{ $console->type }}
                        </option>
                    @endforeach

                </select>

            </div>


            {{-- Games --}}

            <div>

                <label class="label">

                    <span class="label-text">
                        Select Games
                    </span>

                </label>


                @foreach ($consoles as $console)
                    <div class="console-games hidden" data-console="{{ $console->id }}">

                        <div class="grid md:grid-cols-2 gap-3">

                            @foreach ($console->games as $game)
                                <label class="flex items-center gap-3 border rounded-lg p-4 cursor-pointer">

                                    <input type="checkbox" name="games[]" value="{{ $game->id }}" class="checkbox"
                                        @checked(in_array($game->id, old('games', [])))>

                                    <div>

                                        <div class="font-semibold">
                                            {{ $game->title }}
                                        </div>

                                        <div class="text-sm opacity-60">
                                            {{ $game->platform }}
                                        </div>

                                    </div>

                                </label>
                            @endforeach

                        </div>

                    </div>
                @endforeach

            </div>


            <button type="submit" class="btn btn-primary w-full">
                Create Order
            </button>

        </form>

    </div>


    <script>
        const consoleSelect =
            document.getElementById('console_id');

        const consoleGames =
            document.querySelectorAll('.console-games');


        function showGames() {

            const selectedConsole =
                consoleSelect.value;

            consoleGames.forEach(section => {

                section.classList.add('hidden');

                if (
                    section.dataset.console === selectedConsole
                ) {
                    section.classList.remove('hidden');
                }

            });

        }


        consoleSelect.addEventListener(
            'change',
            showGames
        );


        showGames();
    </script>

</x-layout>
