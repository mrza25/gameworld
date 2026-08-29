<x-layout>

    <x-form.form title="Create Game" action="{{ route('games.store') }}" method="POST">

        @csrf

        <x-form.field name="title" label="Game Title" placeholder="Enter game title" required />

        <x-form.field name="genre" label="Genre" placeholder="Action, RPG, Adventure..." required />

        <x-form.field name="platform" label="Platform" placeholder="PC, PS5, Xbox..." required />

        <x-form.field name="size" label="Size (GB)" type="number" placeholder="50.5" step="0.01" min="0"
            required />
        <x-form.field name="price" label="Price" type="number" placeholder="500000" step="0.01" min="0"
            required />

        <button type="submit" class="btn btn-primary w-full">
            Create Game
        </button>

    </x-form.form>

</x-layout>
