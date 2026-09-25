<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        {{ $title ?? 'Game World' }}
    </title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>


<body class="min-h-screen bg-base-200 text-base-content">

    <x-layout.nav />


    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{ $slot }}

    </main>

</body>

</html>
