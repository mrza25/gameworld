<x-layout>

    <div class="max-w-2xl mx-auto py-10">

        <x-form.form title="Create Console" action="{{ route('consoles.store') }}" method="POST">

            @csrf

            <x-form.field name="type" label="Console Type" placeholder="PS5" required />

            <div class="mt-6">

                <label class="label">
                    <span class="label-text font-semibold">
                        Select Games
                    </span>
                </label>

                @if ($games->count())

                    <div class="space-y-2 max-h-80 overflow-y-auto">

                        @foreach ($games as $game)
                            <label
                                class="flex items-center gap-3 border border-base-300 rounded-lg p-3 cursor-pointer hover:bg-base-200">

                                <input type="checkbox" name="games[]" value="{{ $game->id }}"
                                    class="checkbox checkbox-primary" @checked(in_array($game->id, old('games', [])))>

                                <div>

                                    <p class="font-semibold">
                                        {{ $game->title }}
                                    </p>

                                    <p class="text-sm text-base-content/60">
                                        {{ $game->platform }}
                                        ·
                                        {{ $game->size }} GB
                                        ·
                                        {{ $game->genre }}
                                    </p>

                                </div>

                            </label>
                        @endforeach

                    </div>
                @else
                    <div class="alert alert-warning">

                        <div>
                            <h3 class="font-bold">
                                No games available
                            </h3>

                            <p>
                                There are no games in the catalog yet.
                            </p>
                        </div>

                    </div>

                @endif

            </div>


            <button type="submit" class="btn btn-primary w-full mt-6">
                Create Console
            </button>

        </x-form.form>

    </div>

</x-layout>
