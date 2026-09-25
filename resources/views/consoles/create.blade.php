<x-layout>

    <div class="py-8 max-w-2xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('consoles.index') }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Consoles
            </a>

            <h1 class="text-3xl font-bold mt-4">
                Create an Order
            </h1>

            <p class="text-base-content/60 mt-2">
                Consoles are now registered through orders.
            </p>

        </div>


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body text-center py-12">

                <h2 class="text-2xl font-bold">
                    Create a New Order
                </h2>

                <p class="text-base-content/60 mt-3 max-w-md mx-auto">
                    Instead of creating a console separately, choose your
                    console and games when creating an order.
                </p>


                <div class="mt-6">

                    <a href="{{ route('orders.create') }}" class="btn btn-primary">
                        Create Order
                    </a>

                </div>

            </div>

        </div>

    </div>

</x-layout>
