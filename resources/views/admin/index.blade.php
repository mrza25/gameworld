<x-layout>

    <div class="py-8">

        <div class="mb-8">
            <h1 class="text-3xl font-bold">
                Customer Consoles
            </h1>

            <p class="text-base-content/60">
                Manage customer consoles
            </p>
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if ($consoles->count())

            <div class="overflow-x-auto">

                <table class="table">

                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Console</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($consoles as $console)
                            <tr>

                                <td>
                                    {{ $console->user->name }}
                                </td>

                                <td>
                                    {{ $console->user->phonenumber }}
                                </td>

                                <td>
                                    {{ $console->type }}
                                </td>

                                <td>

                                    <span class="badge badge-primary">
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

            </div>

        @endif

    </div>

</x-layout>
