<nav class="border-b border-base-300 bg-base-100">

    <div class="max-w-7xl mx-auto px-4">

        <div class="navbar min-h-16">

            {{-- Brand --}}
            <div class="flex-1">

                <a href="{{ route('home') }}" class="text-xl font-bold">
                    Game World
                </a>

                <span class="badge badge-primary ml-3">
                    Admin
                </span>

            </div>


            {{-- Navigation --}}
            <div class="flex-none">

                <div class="flex items-center gap-5">

                    <a href="{{ route('admin.orders.index') }}" class="hover:text-primary transition">
                        Orders
                    </a>


                    <a href="{{ route('admin.games.create') }}" class="hover:text-primary transition">
                        Add Game
                    </a>


                    <div class="divider divider-horizontal mx-0"></div>


                    <a href="{{ route('home') }}" class="hover:text-primary transition">
                        Website
                    </a>


                    <form action="{{ route('logout') }}" method="POST">
                        @csrf

                        <button type="submit" class="hover:text-error transition">
                            Logout
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

</nav>
