<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Game World</title>
    @vite('resources/css/app.css')
</head>

<body class="bg-background text-foreground ">
    <p class="text-center text-2xl font-bold py-4">Game World</p>
    <x-layout.nav />

    <main class="container mx-auto px-4">
        {{ $slot }}
    </main>
</body>

</html>
