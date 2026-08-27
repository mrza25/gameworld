<x-layout>

    <div class="max-w-xl mx-auto py-10">

        <h1 class="text-3xl font-bold mb-6">
            Add Console
        </h1>

        <x-form.form title="Add Console" action="{{ route('consoles.store') }}" method="POST">

            @csrf

            <x-form.field name="type" label="Console Type" placeholder="PS5" required />

            <button type="submit" class="btn btn-primary w-full">
                Add Console
            </button>

        </x-form.form>

    </div>

</x-layout>
