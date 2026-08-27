<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-6">

            <div>
                <h1 class="text-3xl font-bold">
                    My Consoles
                </h1>

                <p class="text-base-content/60">
                    Manage your consoles
                </p>
            </div>

            <a href="{{ route('consoles.create') }}" class="btn btn-primary">
                Add Console
            </a>

        </div>

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if ($consoles->count())

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                @foreach ($consoles as $console)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">

                            <h2 class="card-title">
                                {{ $console->type }}
                            </h2>

                            <p>
                                Status:
                                <span class="font-semibold">
                                    {{ $console->status->value }}
                                </span>
                            </p>

                            <div class="card-actions justify-end">

                                <a href="{{ route('consoles.show', $console) }}" class="btn btn-sm btn-primary">
                                    View
                                </a>

                                <a href="{{ route('consoles.edit', $console) }}" class="btn btn-sm btn-outline">
                                    Edit
                                </a>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No consoles found
                </h2>

                <p class="text-base-content/60 mt-2">
                    You don't have any consoles yet.
                </p>

                <a href="{{ route('consoles.create') }}" class="btn btn-primary mt-4">
                    Add Your Console
                </a>

            </div>

        @endif

    </div>

</x-layout>
