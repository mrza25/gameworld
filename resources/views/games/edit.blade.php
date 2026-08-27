<x-layout>

    <x-form.form title="Edit Game" action="{{ route('games.update', $game) }}" method="POST">

        @csrf
        @method('PATCH')

        <x-form.field name="title" label="Game Title" placeholder="Enter game title"
            value="{{ old('title', $game->title) }}" required />

        <x-form.field name="genre" label="Genre" placeholder="Action, RPG, Adventure..."
            value="{{ old('genre', $game->genre) }}" required />

        <x-form.field name="platform" label="Platform" placeholder="PC, PS5, Xbox..."
            value="{{ old('platform', $game->platform) }}" required />

        <x-form.field name="size" label="Size (GB)" type="number" placeholder="50.5"
            value="{{ old('size', $game->size) }}" step="0.01" min="0" required />

        <button type="submit" class="btn btn-primary w-full">
            Update Game
        </button>

    </x-form.form>

</x-layout>
