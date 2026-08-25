<nav class="border-b border-border px-6">

    <div class="max-w-7xl mx-auto h-16 flex items-center justify-between">

        <div class="flex items-center space-x-4">

            <a href="/">
                <img src="{{ asset('images/logosite.png') }}" alt="Logo" class="h-12 w-auto">
            </a>

            <a href="/" class="text-lg font-semibold text-foreground">
                Game World
            </a>

            <a href="#" class="text-foreground hover:text-primary">
                Games
            </a>

            <a href="#" class="text-foreground hover:text-primary">
                Create Game
            </a>

        </div>

        <div class="flex items-center space-x-4">

            @auth

                <span class="text-foreground">
                    Hello, {{ Auth::user()->name }}
                </span>

                <form method="POST" action="/logout">
                    @csrf

                    <button type="submit" class="btn">
                        Logout
                    </button>
                </form>
            @else
                <a href="/login" class="text-foreground hover:text-primary">
                    Login
                </a>

                <a href="{{ route('register') }}" class="btn">
                    Register
                </a>

            @endauth

        </div>

    </div>

</nav>
