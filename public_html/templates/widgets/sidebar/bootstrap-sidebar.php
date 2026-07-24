<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Bootstrap Sidebar</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
      <button class="btn btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#bsSidebar">
        ☰
      </button>
      <span class="navbar-brand">My Bootstrap Site</span>
    </div>
  </nav>

  <!-- Sidebar Offcanvas for Mobile -->
  <div class="offcanvas offcanvas-start bg-dark text-white" tabindex="-1" id="bsSidebar">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title">Menu</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link text-white" href="#">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link text-white" href="#">Profile</a></li>
        <li class="nav-item"><a class="nav-link text-white" href="#">Settings</a></li>
        <li class="nav-item"><a class="nav-link text-white" href="#">Logout</a></li>
      </ul>
    </div>
  </div>

  <!-- Static Sidebar for Desktop -->
  <div class="container-fluid">
    <div class="row">
      <nav class="col-lg-2 d-none d-lg-block bg-dark text-white vh-100 p-3">
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link text-white" href="#">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link text-white" href="#">Profile</a></li>
          <li class="nav-item"><a class="nav-link text-white" href="#">Settings</a></li>
          <li class="nav-item"><a class="nav-link text-white" href="#">Logout</a></li>
        </ul>
      </nav>
      <main class="col-lg-10 p-4">
        <h2 class="mb-4">Welcome</h2>
        <p>This is the main content area styled with Bootstrap.</p>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>