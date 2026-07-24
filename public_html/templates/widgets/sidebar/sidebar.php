<?php
// Security: Prevent direct access to this widget file
if (!defined('CUE_CORE_LOADED') && !headers_sent()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('403 Forbidden: Widget files cannot be accessed directly.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Responsive Sidebar (Windows + Mobile)</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: sans-serif;
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar base */
    .sidebar {
      width: 250px;
      background-color: #1e1e2f;
      color: white;
      flex-shrink: 0;
      padding: 20px;
      position: fixed;
      top: 0;
      bottom: 0;
      left: 0;
      transform: translateX(0);
      transition: transform 0.3s ease-in-out;
      z-index: 1000;
    }

    .sidebar h2 {
      margin-bottom: 1rem;
    }

    .sidebar a {
      display: block;
      color: white;
      text-decoration: none;
      padding: 10px 0;
      border-bottom: 1px solid #333;
    }

    .sidebar a:hover {
      background-color: #333;
    }

    /* Main content */
    .main-content {
      flex: 1;
      margin-left: 250px;
      padding: 20px;
      transition: margin-left 0.3s ease-in-out;
    }

    /* Header with toggle button */
    .header {
      background: #2b2b3c;
      color: white;
      padding: 15px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .menu-toggle {
      font-size: 24px;
      background: none;
      border: none;
      color: white;
      cursor: pointer;
      display: none; /* hidden on desktop */
    }

    /* Responsive (mobile) */
    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
      }

      .sidebar.active {
        transform: translateX(0);
      }

      .main-content {
        margin-left: 0;
      }

      .menu-toggle {
        display: block;
      }
    }
  </style>
</head>
<body>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <h2>Sidebar</h2>
    <a href="#">Home</a>
    <a href="#">Dashboard</a>
    <a href="#">Profile</a>
    <a href="#">Settings</a>
    <a href="#">Logout</a>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Header -->
    <div class="header">
      <button class="menu-toggle" id="menuToggle">&#9776;</button>
      <h1>Responsive Sidebar</h1>
    </div>

    <div class="content">
      <h2>Welcome</h2>
      <p>This layout works seamlessly on desktop (e.g., Windows browsers) and mobile devices. The sidebar is always visible on large screens and can be toggled on smaller screens using the hamburger menu.</p>
    </div>
  </div>

  <!-- JavaScript -->
  <script>
    const toggleBtn = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    toggleBtn.addEventListener('click', () => {
      sidebar.classList.toggle('active');
    });

    // Optional: close sidebar on mobile when clicking outside
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 768) {
        if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
          sidebar.classList.remove('active');
        }
      }
    });
  </script>

</body>
</html>