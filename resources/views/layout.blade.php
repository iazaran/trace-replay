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
    
    <!-- Minimal Top Bar -->
    <header class="glass-panel sticky top-0 z-50 px-6 py-2 flex items-center justify-between border-b border-gray-800/50">
        <a href="{{ route('trace-replay.index') }}" class="flex items-center gap-2 text-gray-400 hover:text-white transition-colors">
            <div class="w-6 h-6 rounded bg-gradient-to-br from-brand-500 to-indigo-600 flex items-center justify-center">
                <i data-feather="activity" class="w-3.5 h-3.5 text-white"></i>
            </div>
            <span class="text-sm font-medium">TraceReplay</span>
        </a>
        <div class="flex items-center gap-3">
            <a href="https://github.com/iazaran/trace-replay" target="_blank"
               class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                <span>GitHub</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow p-6 sm:p-8 max-w-7xl mx-auto w-full">
        @yield('content')
    </main>

    <script>
        // Safe feather icon replacement that handles dynamic content
        function replaceFeatherIcons() {
            if (typeof feather === 'undefined') return;

            // Only replace icons that haven't been replaced yet (still have data-feather)
            // and are not inside <template> tags
            const icons = document.querySelectorAll('i[data-feather]:not(template i[data-feather])');
            icons.forEach(icon => {
                try {
                    const name = icon.getAttribute('data-feather');
                    if (name && feather.icons[name]) {
                        const svg = feather.icons[name].toSvg({
                            class: icon.getAttribute('class') || ''
                        });
                        const temp = document.createElement('div');
                        temp.innerHTML = svg;
                        const svgElement = temp.firstChild;
                        if (svgElement && icon.parentNode) {
                            icon.parentNode.replaceChild(svgElement, icon);
                        }
                    }
                } catch (e) {
                    // Silently ignore replacement errors
                }
            });
        }

        // Initialize after DOM is ready
        document.addEventListener('DOMContentLoaded', replaceFeatherIcons);

        // Re-run after Alpine.js initializes
        document.addEventListener('alpine:initialized', () => {
            setTimeout(replaceFeatherIcons, 50);
        });
    </script>
</body>
</html>
