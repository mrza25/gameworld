```blade
<x-layout>

    <div class="py-8 max-w-2xl mx-auto">

        <div class="mb-8">
            <h1 class="text-3xl font-bold">
                Create Game
            </h1>

            <p class="text-base-content/60 mt-2">
                Add a new game to the game catalog.
            </p>
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

                <form action="{{ route('games.store') }}" method="POST" class="space-y-6">
                    @csrf

                    <x-form.field name="title" label="Title" placeholder="Game title" required />

                    <x-form.field name="genre" label="Genre" placeholder="Action, RPG, Adventure..." required />

                    <x-form.field name="platform" label="Platform" placeholder="PS5, PS4, Xbox..." required />

                    <x-form.field name="size" label="Size (GB)" type="number" placeholder="Game size" step="0.01"
                        min="0" required />

                    <x-form.field name="price" label="Price" type="number" placeholder="Game price" step="0.01"
                        min="0" required />

                    <div class="flex justify-end gap-3">

                        <a href="{{ route('games.index') }}" class="btn btn-outline">
                            Cancel
                        </a>

                        <button type="submit" class="btn btn-primary">
                            Create Game
                        </button>

                    </div>

                </form>

            </div>
        </div>

    </div>

</x-layout>
```
