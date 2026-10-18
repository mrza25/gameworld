<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-8">

            <div>

                <h1 class="text-3xl font-bold">
                    My Orders
                </h1>

                <p class="text-base-content/60 mt-1">
                    View and track your orders.
                </p>

            </div>


            <a href="{{ route('orders.create') }}" class="btn btn-primary">
                New Order
            </a>

        </div>


        {{-- Success Message --}}

        @if (session('success'))
            <div class="alert alert-success mb-6">

                {{ session('success') }}

            </div>
        @endif


        {{-- Orders --}}

        @if ($orders->isNotEmpty())

            <div class="space-y-5">

                @foreach ($orders as $order)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">


                            {{-- Header --}}

                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">

                                <div>

                                    <h2 class="card-title">
                                        Order #{{ $order->id }}
                                    </h2>

                                    <p class="text-sm text-base-content/60 mt-1">

                                        {{ $order->created_at->format('Y-m-d H:i') }}

                                    </p>

                                </div>


                                <div class="flex flex-col sm:items-end gap-2">

                                    @php
                                        $statusClass = match ($order->status->value) {
                                            'pending' => 'badge-warning',
                                            'processing' => 'badge-info',
                                            'completed' => 'badge-success',
                                            default => 'badge-neutral',
                                        };
                                    @endphp

                                    <span class="badge {{ $statusClass }}">

                                        {{ ucfirst($order->status->value) }}

                                    </span>

                                    <span class="text-sm text-base-content/60">

                                        Payment:
                                        <span class="font-medium text-base-content">

                                            {{ ucfirst($order->payment_status->value) }}

                                        </span>

                                    </span>

                                </div>

                            </div>


                            <div class="divider"></div>


                            {{-- Order Info --}}

                            <div class="grid sm:grid-cols-2 gap-4">

                                <div>

                                    <p class="text-sm text-base-content/60">
                                        Console
                                    </p>

                                    <p class="font-semibold mt-1">
                                        {{ $order->console_type }}
                                    </p>

                                </div>


                                <div>

                                    <p class="text-sm text-base-content/60">
                                        Total
                                    </p>

                                    <p class="font-semibold mt-1">
                                        {{ $order->total }}
                                    </p>

                                </div>

                            </div>


                            {{-- Games --}}

                            <div class="mt-5">

                                <p class="text-sm font-semibold mb-2">
                                    Games
                                </p>

                                <div class="flex flex-wrap gap-2">

                                    @foreach ($order->items as $item)
                                        <span class="badge badge-outline">

                                            {{ $item->game->title }}

                                        </span>
                                    @endforeach

                                </div>

                            </div>


                            {{-- Actions --}}

                            <div class="card-actions justify-end mt-5">

                                <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-primary">
                                    View Order
                                </a>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            {{-- Empty State --}}

            <div class="card bg-base-100 shadow-xl">

                <div class="card-body text-center py-16">

                    <h2 class="text-2xl font-bold">
                        No Orders Yet
                    </h2>

                    <p class="text-base-content/60 mt-2">
                        You haven't created any orders yet.
                    </p>

                    <div class="mt-5">

                        <a href="{{ route('orders.create') }}" class="btn btn-primary">
                            Create Your First Order
                        </a>

                    </div>

                </div>

            </div>

        @endif

    </div>

</x-layout>
