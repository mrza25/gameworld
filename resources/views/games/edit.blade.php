<x-layout>

    <div class="py-8 max-w-2xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('games.show', $game) }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Game
            </a>

            <h1 class="text-3xl font-bold mt-4">
                Edit Game
            </h1>

        </div>


        @if ($errors->any())

            <div class="alert alert-error mb-6">

                <ul class="list-disc list-inside">

                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach

                </ul>

            </div>

        @endif


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <form action="{{ route('admin.games.update', $game) }}" method="POST" class="space-y-5">

                    @csrf
                    @method('PATCH')


                    <div>

                        <label for="title" class="label">
                            <span class="label-text">
                                Title
                            </span>
                        </label>

                        <input type="text" id="title" name="title" value="{{ old('title', $game->title) }}"
                            class="input input-bordered w-full" required>

                        @error('title')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    <div>

                        <label for="genre" class="label">
                            <span class="label-text">
                                Genre
                            </span>
                        </label>

                        <input type="text" id="genre" name="genre" value="{{ old('genre', $game->genre) }}"
                            class="input input-bordered w-full" required>

                        @error('genre')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    <div>

                        <label for="platform" class="label">
                            <span class="label-text">
                                Platform
                            </span>
                        </label>

                        <input type="text" id="platform" name="platform"
                            value="{{ old('platform', $game->platform) }}" class="input input-bordered w-full" required>

                        @error('platform')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    <div>

                        <label for="size" class="label">
                            <span class="label-text">
                                Size
                            </span>
                        </label>

                        <input type="text" id="size" name="size" value="{{ old('size', $game->size) }}"
                            class="input input-bordered w-full" required>

                        @error('size')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    <div>

                        <label for="price" class="label">
                            <span class="label-text">
                                Price
                            </span>
                        </label>

                        <input type="number" id="price" name="price" value="{{ old('price', $game->price) }}"
                            min="0" step="0.01" class="input input-bordered w-full" required>

                        @error('price')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>


                    <div class="flex justify-end gap-3 pt-4">

                        <a href="{{ route('games.show', $game) }}" class="btn btn-ghost">
                            Cancel
                        </a>

                        <button type="submit" class="btn btn-primary">
                            Update Game
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</x-layout>
