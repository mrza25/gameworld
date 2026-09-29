<x-layout>

    <div class="py-8">

        {{-- Header --}}
        <div class="mb-8">

            <h1 class="text-3xl font-bold">
                Admin Dashboard
            </h1>

            <p class="text-base-content/60 mt-1">
                Overview of your Game World store.
            </p>

        </div>


        {{-- Statistics --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">

            {{-- Games --}}
            <div class="stat bg-base-100 shadow rounded-box">

                <div class="stat-title">
                    Games
                </div>

                <div class="stat-value text-primary">
                    {{ $stats['games'] }}
                </div>

                <div class="stat-desc">
                    Total games
                </div>

            </div>


            {{-- Orders --}}
            <div class="stat bg-base-100 shadow rounded-box">

                <div class="stat-title">
                    Orders
                </div>

                <div class="stat-value">
                    {{ $stats['orders'] }}
                </div>

                <div class="stat-desc">
                    Total orders
                </div>

            </div>


            {{-- Pending --}}
            <div class="stat bg-base-100 shadow rounded-box">

                <div class="stat-title">
                    Pending
                </div>

                <div class="stat-value text-warning">
                    {{ $stats['pending_orders'] }}
                </div>

                <div class="stat-desc">
                    Waiting for processing
                </div>

            </div>


            {{-- Processing --}}
            <div class="stat bg-base-100 shadow rounded-box">

                <div class="stat-title">
                    Processing
                </div>

                <div class="stat-value text-info">
                    {{ $stats['processing_orders'] }}
                </div>

                <div class="stat-desc">
                    Currently installing
                </div>

            </div>


            {{-- Ready --}}
            <div class="stat bg-base-100 shadow rounded-box">

                <div class="stat-title">
                    Ready
                </div>

                <div class="stat-value text-success">
                    {{ $stats['ready_orders'] }}
                </div>

                <div class="stat-desc">
                    Ready for delivery
                </div>

            </div>

        </div>


        {{-- Recent Orders --}}
        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <div class="flex items-center justify-between mb-4">

                    <div>

                        <h2 class="card-title">
                            Recent Orders
                        </h2>

                        <p class="text-sm text-base-content/60">
                            Latest orders placed by customers.
                        </p>

                    </div>

                    <a href="{{ route('admin.orders.index') }}" class="btn btn-primary btn-sm">
                        View All
                    </a>

                </div>


                @if ($recentOrders->isEmpty())

                    <div class="text-center py-10">

                        <p class="font-semibold">
                            No orders yet.
                        </p>

                        <p class="text-sm text-base-content/60 mt-1">
                            Orders will appear here once customers place them.
                        </p>

                    </div>
                @else
                    <div class="overflow-x-auto">

                        <table class="table">

                            <thead>

                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Console</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>

                            </thead>


                            <tbody>

                                @foreach ($recentOrders as $order)
                                    <tr>

                                        <td>
                                            <span class="font-semibold">
                                                #{{ $order->id }}
                                            </span>
                                        </td>


                                        <td>
                                            {{ $order->user->name }}
                                        </td>


                                        <td>
                                            {{ $order->console_type }}
                                        </td>


                                        <td>

                                            @php
                                                $status = $order->status->value;

                                                $statusClass = match ($status) {
                                                    'pending' => 'badge-warning',
                                                    'confirmed' => 'badge-info',
                                                    'processing' => 'badge-info',
                                                    'ready' => 'badge-success',
                                                    'completed' => 'badge-success',
                                                    'cancelled' => 'badge-error',
                                                    default => 'badge-neutral',
                                                };
                                            @endphp

                                            <span class="badge {{ $statusClass }}">
                                                {{ ucfirst($status) }}
                                            </span>

                                        </td>


                                        <td>
                                            {{ $order->created_at->format('Y-m-d') }}
                                        </td>


                                        <td>

                                            <a href="{{ route('admin.orders.show', $order) }}"
                                                class="btn btn-ghost btn-sm">
                                                View
                                            </a>

                                        </td>

                                    </tr>
                                @endforeach

                            </tbody>

                        </table>

                    </div>

                @endif

            </div>

        </div>

    </div>

</x-layout>
