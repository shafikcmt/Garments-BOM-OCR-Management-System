<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard')</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>

/* ================= SIDEBAR BASE ================= */
.sidebar {
    width: 270px;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 1020;
    background-color: #151E3F;
    color: #fff;
}

/* ================= NAV LINKS ================= */
.sidebar .nav-link {
    display: flex;
    align-items: center;
    width: 98%;
    overflow: hidden;

    padding: 12px 20px;
    margin: 4px 10px;
    border-radius: 6px;

    color: #fff;
    cursor: pointer;
    transition: background-color 0.2s ease-in-out;
}

/* 🔥 THIS IS THE MOST IMPORTANT FIX */
.sidebar .nav-link span {
    flex: 1 1 1;      /* allow flex but don’t push */
    min-width: 0;        /* prevent overflow bug */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Prevent icon from shrinking */
.sidebar .nav-link i {
    flex-shrink: 0;
}

/* Hover effect */
.sidebar .nav-link:hover {
    background-color: #1f2a57;
    color: #fff;
}

/* ================= SUB MENU ================= */
.sidebar .collapse .nav-link {
    padding-left: 36px;
}

.sidebar .collapse .nav-link:hover {
    background-color: #2a376f;
    color: #fff;
}

/* ================= ACTIVE STATE (OPTIONAL) ================= */
.sidebar .nav-link.active {
    background-color: #2a376f;
    font-weight: 600;
}


/* ================= HEADER ================= */
.header {
    height: 70px;
    background: #5B89BA; /* updated background */
    color: #fff; /* text color contrasting background */
    position: fixed;
    top: 0;
    left: 270px;
    width: calc(100% - 270px);
    z-index: 1030;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
    border-bottom: 1px solid #4973a3; /* slightly darker border */
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Links & icons in header */
.header a,
.header i {
    color: #fff;
    transition: all 0.2s ease-in-out;
}

.header a:hover,
.header i:hover {
    color: #e0e0e0;
}

/* Profile wrapper */
.profile-wrapper {
    position: relative;
}

.profile-img {
    height: 42px;
    width: 42px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid #fff; /* contrast border */
    transition: all 0.2s ease-in-out;
}

.profile-img:hover {
    transform: scale(1.05);
}

/* Profile card dropdown */
.profile-card {
    position: absolute;
    right: 0;
    top: 55px;
    width: 220px;
    background: #fff;
    color: #333;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    padding: 12px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(5px);
    transition: all 0.2s ease-in-out;
    z-index: 2000;
}

/* Show card on hover */
.profile-wrapper:hover .profile-card,
.profile-card:hover {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.profile-card .dropdown-item {
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 14px;
    color: #333;
}

.profile-card .dropdown-item:hover {
    background-color: #f1f3f5;
    color: #000;
}

/* Adjust content to match header */
.content {
    margin-top: 70px;
    margin-left: 270px;
    padding: 20px;
}

#sidebarMenu .collapse {
    transition: height 0.3s ease;
}

</style>

</head>
<body>

<!-- Sidebar -->
@include('layouts.sidebar')

<!-- Navbar -->
@include('layouts.navbar')

<!-- Main Content -->
<div class="content">
    @yield('content')
</div>


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Sidebar toggle for mobile
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');

    sidebarToggle?.addEventListener('click', () => {
        sidebar.classList.toggle('d-none');
    });

    sidebarClose?.addEventListener('click', () => {
        sidebar.classList.add('d-none');
    });

    document.addEventListener("DOMContentLoaded", () => {
    const collapseToggles = document.querySelectorAll('#sidebarMenu a[data-bs-toggle="collapse"]');

    collapseToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default anchor behavior

            const targetId = this.getAttribute('href');
            const target = document.querySelector(targetId);

            // Collapse all other menus
            document.querySelectorAll('#sidebarMenu .collapse').forEach(collapse => {
                if (collapse !== target) {
                    collapse.classList.remove('show');
                }
            });

            // Toggle current menu
            if (target.classList.contains('show')) {
                target.classList.remove('show');
            } else {
                target.classList.add('show');
            }
        });
    });
});

</script>
@yield('scripts')
</body>
</html>
