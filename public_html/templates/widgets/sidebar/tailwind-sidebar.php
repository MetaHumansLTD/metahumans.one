<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tailwind Sidebar</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

  <!-- Header -->
  <header class="flex items-center justify-between p-4 bg-gray-800 text-white">
    <button id="tw-menu-toggle" class="lg:hidden text-2xl">&#9776;</button>
    <h1 class="text-lg">My Tailwind Site</h1>
  </header>

  <div class="flex">
    <!-- Sidebar -->
    <aside id="tw-sidebar" class="fixed lg:static top-0 left-0 h-full w-64 bg-gray-700 text-white p-4 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 z-50">
      <nav>
        <ul class="space-y-4">
          <li><a href="#" class="block hover:underline">Dashboard</a></li>
          <li><a href="#" class="block hover:underline">Profile</a></li>
          <li><a href="#" class="block hover:underline">Settings</a></li>
          <li><a href="#" class="block hover:underline">Logout</a></li>
        </ul>
      </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-6 lg:ml-64">
      <h2 class="text-2xl font-bold">Welcome</h2>
      <p class="mt-4">This is the main content area with Tailwind styling.</p>
    </main>
  </div>

  <script>
    const twToggleBtn = document.getElementById('tw-menu-toggle');
    const twSidebar = document.getElementById('tw-sidebar');

    twToggleBtn.addEventListener('click', () => {
      twSidebar.classList.toggle('-translate-x-full');
    });
  </script>
</body>
</html>