<x-layout>

    <div class="py-8">

        <div class="mb-8">
            <h1 class="text-3xl font-bold">
                Manage Consoles
            </h1>

            <p class="text-base-content/60 mt-2">
                View and manage all customer consoles.
            </p>
        </div>

        @if ($consoles->count())

            <div class="overflow-x-auto">

                <table class="table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Console</th>
                            <th>Games</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($consoles as $console)
                            <tr>

                                <td>
                                    {{ $console->id }}
                                </td>

                                <td>
                                    <div class="font-semibold">
                                        {{ $console->user->name }}
                                    </div>
                                </td>

                                <td>
                                    {{ $console->user->phonenumber }}
                                </td>

                                <td>
                                    {{ $console->type }}
                                </td>

                                <td>
                                    {{ $console->games->count() }}
                                </td>

                                <td>

                                    <span class="badge badge-info">
                                        {{ $console->status->value }}
                                    </span>

                                </td>

                                <td>

                                    <a href="{{ route('admin.consoles.show', $console) }}" class="btn btn-sm btn-primary">
                                        Manage
                                    </a>

                                </td>

                            </tr>
                        @endforeach

                    </tbody>

                </table>

            </div>
        @else
            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No consoles found
                </h2>

                <p class="text-base-content/60 mt-2">
                    There are no registered consoles yet.
                </p>

            </div>

        @endif

    </div>

</x-layout>
