<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Responsive Sidebar</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>

  <header class="header">
    <button id="menu-toggle" class="menu-toggle">&#9776;</button>
    <h1 class="site-title">My Site</h1>
  </header>

  <div class="container">
    <aside class="sidebar" id="sidebar">
      <nav>
        <ul>
          <li><a href="#">Dashboard</a></li>
          <li><a href="#">Profile</a></li>
          <li><a href="#">Settings</a></li>
          <li><a href="#">Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="content">
      <h2>Welcome</h2>
      <p>This is the main content area.</p>
    </main>
  </div>

  <script src="script.js"></script>
</body>
</html>