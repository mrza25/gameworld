<x-layout>

    <div class="py-8">

        <div class="flex items-center justify-between mb-8">

            <div>

                <h1 class="text-3xl font-bold">
                    My Orders
                </h1>

                <p class="text-base-content/60">
                    View your orders
                </p>

            </div>

            <a href="{{ route('orders.create') }}" class="btn btn-primary">
                New Order
            </a>

        </div>


        @if ($orders->count())

            <div class="space-y-4">

                @foreach ($orders as $order)
                    <div class="card bg-base-100 shadow-xl">

                        <div class="card-body">

                            <div class="flex justify-between">

                                <div>

                                    <h2 class="card-title">
                                        Order #{{ $order->id }}
                                    </h2>

                                    <p>
                                        Console:
                                        {{ $order->console?->type ?? 'N/A' }}
                                    </p>

                                </div>


                                <div class="text-right">

                                    <div class="badge badge-warning">
                                        {{ $order->status->value }}
                                    </div>

                                    <p class="mt-2 font-bold">
                                        {{ $order->total }}
                                    </p>

                                </div>

                            </div>


                            <div class="card-actions justify-end">

                                <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-primary">
                                    View Order
                                </a>

                            </div>

                        </div>

                    </div>
                @endforeach

            </div>
        @else
            <div class="text-center py-16">

                <h2 class="text-2xl font-bold">
                    No orders yet
                </h2>

                <a href="{{ route('orders.create') }}" class="btn btn-primary mt-4">
                    Create Your First Order
                </a>

            </div>

        @endif

    </div>

</x-layout>
