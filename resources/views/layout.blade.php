<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TraceReplay — Laravel Debugging Engine')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['Fira Code', 'monospace'],
                    },
                    colors: {
                        dark: {
                            900: '#0f1117',
                            800: '#161b22',
                            700: '#21262d',
                        },
                        brand: {
                            500: '#3b82f6',
                            600: '#2563eb',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Page-specific scripts that must register before Alpine -->
    @yield('scripts')

    <!-- Alpine.js - defer ensures it runs after inline scripts -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    
    <!-- Feather Icons -->
    <script src="https://unpkg.com/feather-icons"></script>

    <style>
        /* Hide elements with x-cloak until Alpine initializes */
        [x-cloak] { display: none !important; }

        body {
            background-color: #0f1117;
            color: #c9d1d9;
        }
        .glass-panel {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #0f1117; }
        ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #4b5563; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col font-sans">
    
    <!-- Top Navigation -->
    <header class="glass-panel sticky top-0 z-50 px-6 py-4 flex items-center justify-between border-b border-gray-800">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded bg-gradient-to-br from-brand-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-brand-500/20">
                <i data-feather="activity" class="w-5 h-5 text-white"></i>
            </div>
            <div>
                <h1 class="font-bold text-lg text-white leading-tight">TraceReplay</h1>
                <p class="text-xs text-gray-400">Laravel Debugging Engine</p>
            </div>
        </div>
        <nav class="flex gap-4 items-center">
            <a href="{{ route('trace-replay.index') }}" class="text-sm font-medium text-gray-300 hover:text-white transition-colors">Traces</a>
            <a href="{{ route('trace-replay.index') }}?status=error" class="text-sm font-medium text-gray-300 hover:text-white transition-colors">Errors</a>
            <a href="{{ route('trace-replay.index') }}?status=processing" class="text-sm font-medium text-gray-300 hover:text-white transition-colors">In&nbsp;Progress</a>
            <a href="https://github.com/iazaran/trace-replay" target="_blank" class="text-sm font-medium text-gray-500 hover:text-gray-300 transition-colors">GitHub ↗</a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="flex-grow p-6 sm:p-8 max-w-7xl mx-auto w-full">
        @yield('content')
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            feather.replace();
        });
    </script>
</body>
</html>
