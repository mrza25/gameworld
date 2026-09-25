<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('orders.index') }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Orders
            </a>

            <h1 class="text-3xl font-bold mt-4">
                Create New Order
            </h1>

            <p class="text-base-content/60 mt-2">
                Choose your console and the games you want installed.
            </p>

        </div>


        {{-- Validation Errors --}}

        @if ($errors->any())

            <div class="alert alert-error mb-6">

                <ul class="list-disc list-inside">

                    @foreach ($errors->all() as $error)
                        <li>
                            {{ $error }}
                        </li>
                    @endforeach

                </ul>

            </div>

        @endif


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <form action="{{ route('orders.store') }}" method="POST" class="space-y-6">

                    @csrf


                    {{-- Console Type --}}

                    <div>

                        <label for="console_type" class="label">
                            <span class="label-text font-semibold">
                                Console
                            </span>
                        </label>

                        <select name="console_type" id="console_type" class="select select-bordered w-full" required>

                            <option value="">
                                Select your console
                            </option>

                            <option value="PS5" @selected(old('console_type') === 'PS5')>
                                PlayStation 5
                            </option>

                            <option value="PS4" @selected(old('console_type') === 'PS4')>
                                PlayStation 4
                            </option>

                            <option value="Xbox Series X" @selected(old('console_type') === 'Xbox Series X')>
                                Xbox Series X
                            </option>

                            <option value="Xbox One" @selected(old('console_type') === 'Xbox One')>
                                Xbox One
                            </option>

                        </select>

                        @error('console_type')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    {{-- Games --}}

                    <div>

                        <div class="mb-3">

                            <h2 class="font-semibold">
                                Select Games
                            </h2>

                            <p class="text-sm text-base-content/60 mt-1">
                                Select at least one game.
                            </p>

                        </div>


                        @if ($games->isEmpty())

                            <div class="alert alert-warning">

                                No games are currently available.

                            </div>
                        @else
                            <div class="space-y-3">

                                @foreach ($games as $game)
                                    <label
                                        class="flex items-center justify-between gap-4 p-4 border border-base-300 rounded-lg cursor-pointer hover:bg-base-200">

                                        <div class="flex items-center gap-3">

                                            <input type="checkbox" name="games[]" value="{{ $game->id }}"
                                                class="checkbox checkbox-primary" @checked(in_array($game->id, old('games', [])))>

                                            <div>

                                                <div class="font-semibold">
                                                    {{ $game->title }}
                                                </div>

                                                <div class="text-sm text-base-content/60">

                                                    {{ $game->platform }}

                                                    @if ($game->genre)
                                                        · {{ $game->genre }}
                                                    @endif

                                                    @if ($game->size)
                                                        · {{ $game->size }}
                                                    @endif

                                                </div>

                                            </div>

                                        </div>


                                        <div class="font-semibold whitespace-nowrap">

                                            {{ $game->price }}

                                        </div>

                                    </label>
                                @endforeach

                            </div>

                        @endif


                        @error('games')
                            <p class="text-error text-sm mt-2">
                                {{ $message }}
                            </p>
                        @enderror

                        @error('games.*')
                            <p class="text-error text-sm mt-2">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    {{-- Actions --}}

                    <div class="flex justify-end gap-3 pt-4">

                        <a href="{{ route('orders.index') }}" class="btn btn-ghost">
                            Cancel
                        </a>

                        <button type="submit" class="btn btn-primary" @disabled($games->isEmpty())>
                            Create Order
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</x-layout>
