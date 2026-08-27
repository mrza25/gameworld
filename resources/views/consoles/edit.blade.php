<x-layout>

    <div class="max-w-xl mx-auto py-10">

        <h1 class="text-3xl font-bold mb-6">
            Edit Console
        </h1>

        <x-form.form title="Edit Console" action="{{ route('consoles.update', $console) }}" method="POST">

            @csrf
            @method('PUT')

            <x-form.field name="type" label="Console Type" value="{{ old('type', $console->type) }}" required />

            <button type="submit" class="btn btn-primary w-full">
                Update Console
            </button>

        </x-form.form>

    </div>

</x-layout>
