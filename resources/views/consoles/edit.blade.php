<x-layout>

    <div class="py-8 max-w-2xl mx-auto">

        <div class="mb-8">

            <a href="{{ route('consoles.show', $console) }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Console
            </a>

            <h1 class="text-3xl font-bold mt-4">
                Console Management
            </h1>

        </div>


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body text-center py-12">

                <h2 class="text-2xl font-bold">
                    Console Management Has Changed
                </h2>

                <p class="text-base-content/60 mt-3 max-w-lg mx-auto">
                    Console information is now managed through orders.
                    To request a console with games, create a new order.
                </p>


                <div class="flex justify-center gap-3 mt-6">

                    <a href="{{ route('consoles.show', $console) }}" class="btn btn-ghost">
                        Back
                    </a>

                    <a href="{{ route('orders.create') }}" class="btn btn-primary">
                        Create Order
                    </a>

                </div>

            </div>

        </div>

    </div>

</x-layout>
