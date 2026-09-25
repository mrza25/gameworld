@extends('admin.layout.layout')

@section('title', 'Orders')

@section('content')

    <div class="space-y-6">

        <div>
            <h1 class="text-3xl font-bold">
                Orders
            </h1>

            <p class="text-base-content/60 mt-1">
                Manage customer orders.
            </p>
        </div>


        <div class="card bg-base-100 shadow">

            <div class="card-body">

                <div class="overflow-x-auto">

                    <table class="table">

                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Console</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse ($orders as $order)
                                <tr>

                                    <td>
                                        #{{ $order->id }}
                                    </td>

                                    <td>
                                        {{ $order->user->name }}
                                    </td>

                                    <td>
                                        {{ $order->console_type }}
                                    </td>

                                    <td>
                                        {{ number_format($order->total, 2) }}
                                    </td>

                                    <td>
                                        <span class="badge badge-primary">
                                            {{ $order->status->value }}
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge badge-outline">
                                            {{ $order->payment_status->value }}
                                        </span>
                                    </td>

                                    <td>
                                        <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-sm btn-primary">
                                            View
                                        </a>
                                    </td>

                                </tr>

                            @empty

                                <tr>
                                    <td colspan="7" class="text-center py-10">
                                        No orders found.
                                    </td>
                                </tr>
                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

@endsection
