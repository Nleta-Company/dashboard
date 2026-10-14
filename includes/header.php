<?php
/* =========================================================
   NLETA COMMON HEADER
   =========================================================

   Supports:

   /admin/
   /owner/
   /maintenance/
   /inspector/
   /root

   Automatically calculates the correct relative
   path for CSS, JS, images and other assets.

========================================================= */


/* =========================================================
   BASE URL
========================================================= */

$_base = '';

$scriptDir =
    dirname(
        $_SERVER['PHP_SELF'] ?? ''
    );


/* =========================================================
   DASHBOARD SUBDIRECTORIES
========================================================= */

$dashboardFolders = [

    '/owner',

    '/admin',

    '/maintenance',

    '/inspector'

];


foreach (
    $dashboardFolders
    as $folder
) {

    if (
        strpos(
            $scriptDir,
            $folder
        ) !== false
    ) {

        $_base = '../';

        break;
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<title>NLETA Maintenance System</title>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>


<!-- =======================================================
     BOOTSTRAP CSS
======================================================= -->

<link
    rel="stylesheet"
    href="<?= $_base ?>assets/css/bootstrap.min.css"
>


<!-- =======================================================
     BOOTSTRAP ICONS
======================================================= -->

<link
    rel="stylesheet"
    href="<?= $_base ?>assets/css/bootstrap-icons.min.css"
>


<!-- =======================================================
     CHART JS
======================================================= -->

<script
    src="<?= $_base ?>assets/js/chart.min.js"
></script>


<style>

/* =========================================================
   BODY
========================================================= */

body {

    background: #f4f6f9;

}


/* =========================================================
   NAVBAR
========================================================= */

.navbar {

    position: fixed;

    top: 0;

    left: 0;

    width: 100%;

    height: 70px;

    z-index: 1050;

}


/* =========================================================
   NLETA FULL NAME - CENTER
========================================================= */

.nleta-full-name {

    position: absolute;

    left: 50%;

    transform:
        translateX(-50%);

    color: #ffffff;

    font-size: 20px;

    font-weight: 600;

    white-space: nowrap;

    text-align: center;

    line-height: 1.2;

    pointer-events: none;

}


/* =========================================================
   NLETA HEADER LOGO
========================================================= */

.nleta-header-logo {

    height: 50px;

    width: 50px;

    object-fit: contain;

    display: block;

    background: #ffffff;

    border-radius: 4px;

}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    width: 240px;

    height: 100vh;

    position: fixed;

    top: 70px;

    left: 0;

    background: #0d1b3d;

    overflow-y: auto;

    z-index: 1040;

    transition:
        transform 0.3s ease;

}


/* =========================================================
   SIDEBAR LINKS
========================================================= */

.sidebar a {

    color: #ffffff;

    padding: 12px 20px;

    display: flex;

    align-items: center;

    gap: 10px;

    text-decoration: none;

    font-size: 14px;

}


/* =========================================================
   SIDEBAR HOVER
========================================================= */

.sidebar a:hover {

    background:
        rgba(
            255,
            255,
            255,
            0.15
        );

}


/* =========================================================
   SIDEBAR ACTIVE
========================================================= */

.sidebar a.active {

    background:
        rgba(
            255,
            255,
            255,
            0.25
        );

}


/* =========================================================
   CONTENT
========================================================= */

.content {

    margin-left: 240px;

    margin-top: 70px;

    padding: 20px;

}


/* =========================================================
   TABLE RESPONSIVE
========================================================= */

.table-wrapper {

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;

}


.table {

    min-width: 500px;

}


/* =========================================================
   SIDEBAR OVERLAY
========================================================= */

.sidebar-overlay {

    display: none;

    position: fixed;

    top: 0;

    left: 0;

    width: 100%;

    height: 100%;

    background:
        rgba(
            0,
            0,
            0,
            0.5
        );

    z-index: 1039;

}


.sidebar-overlay.show {

    display: block;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {


    /* =====================================================
       NAVBAR
    ====================================================== */

    .navbar {

        height: 56px;

        padding-left:
            10px !important;

        padding-right:
            10px !important;

    }


    /* =====================================================
       SIDEBAR
    ====================================================== */

    .sidebar {

        transform:
            translateX(-100%);

        top: 56px;

        width: 260px;

    }


    .sidebar.open {

        transform:
            translateX(0);

    }


    /* =====================================================
       CONTENT
    ====================================================== */

    .content {

        margin-left: 0 !important;

        margin-top: 56px;

        padding: 12px;

    }


    /* =====================================================
       NLETA LOGO
    ====================================================== */

    .nleta-header-logo {

        height: 36px !important;

        width: 36px !important;

    }


    .navbar-brand span {

        font-size:
            16px !important;

    }


    /* =====================================================
       CENTER FULL NAME
    ====================================================== */

    .nleta-full-name {

        font-size: 14px;

        max-width: 45%;

        overflow: hidden;

        text-overflow: ellipsis;

        white-space: nowrap;

    }


    /* =====================================================
       CARDS
    ====================================================== */

    .card {

        margin-bottom: 12px;

    }


    /* =====================================================
       HEADINGS
    ====================================================== */

    h3 {

        font-size: 18px;

    }


    h4 {

        font-size: 16px;

    }


    /* =====================================================
       TABLES
    ====================================================== */

    .table-responsive,
    table.table {

        display: block;

        overflow-x: auto;

        -webkit-overflow-scrolling: touch;

    }


    /* =====================================================
       STACK CARDS
    ====================================================== */

    .row > [class*='col-md'] {

        flex:
            0 0 100%;

        max-width:
            100%;

    }


    /* =====================================================
       MOBILE BUTTONS
    ====================================================== */

    .btn-mobile-full {

        width:
            100% !important;

        margin-bottom:
            8px;

    }


    /* =====================================================
       FORMS
    ====================================================== */

    .card.p-4 {

        padding:
            16px !important;

    }

}


/* =========================================================
   SMALL MOBILE
========================================================= */

@media (max-width: 480px) {

    .nleta-full-name {

        font-size: 11px;

        max-width: 40%;

    }

}

</style>

</head>


<body>


<!-- =======================================================
     SIDEBAR OVERLAY
======================================================= -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="toggleSidebar()"
>
</div>


<!-- =======================================================
     NAVBAR
======================================================= -->

<nav
    class="
        navbar
        navbar-dark
        bg-dark
        px-3
    "
>


    <!-- ===================================================
         LEFT SIDE
         MENU + LOGO
    ==================================================== -->

    <div
        class="
            d-flex
            align-items-center
            gap-2
        "
    >


        <!-- ===============================================
             MOBILE MENU BUTTON
        ================================================ -->

        <button
            class="
                btn
                btn-outline-light
                btn-sm
                d-md-none
                me-2
            "
            onclick="toggleSidebar()"
            id="sidebarToggle"
            type="button"
        >

            <i
                class="
                    bi
                    bi-list
                "
                style="font-size:20px"
            ></i>

        </button>


        <!-- ===============================================
             NLETA LOGO
        ================================================ -->

        <a
            class="
                navbar-brand
                d-flex
                align-items-center
                gap-2
                mb-0
            "
            href="#"
        >

            <img
                src="<?= $_base ?>assets/images/nleta-logo.jpeg"
                alt="NLETA"
                class="nleta-header-logo"
            >


            <span
                class="
                    fw-bold
                    d-none
                    d-sm-inline
                "
                style="font-size:20px;"
            >

                NLETA

            </span>

        </a>

    </div>


    <!-- ===================================================
         CENTER
         FULL NLETA NAME
    ==================================================== -->

    <div
        class="nleta-full-name"
    >

        National Lift Escalator Testing Agency

    </div>


    <!-- ===================================================
         RIGHT SIDE
         PROFILE + LOGOUT
    ==================================================== -->

    <div
        class="
            text-white
            d-flex
            align-items-center
            gap-2
        "
    >


        <!-- ===============================================
             PROFILE
        ================================================ -->

        <a
            href="<?= $_base ?>profile.php"
            class="
                text-white
                text-decoration-none
                d-flex
                align-items-center
                gap-2
            "
            title="View Profile"
        >

            <i
                class="
                    bi
                    bi-person-circle
                "
            ></i>


            <span
                class="
                    d-none
                    d-sm-inline
                "
            >

                <?= htmlspecialchars(
                    ucfirst(
                        $_SESSION['role']
                        ?? 'User'
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </span>

        </a>


        <!-- ===============================================
             LOGOUT
        ================================================ -->

        <a
            href="<?= $_base ?>logout.php"
            class="
                btn
                btn-sm
                btn-danger
                ms-1
            "
        >

            <i
                class="
                    bi
                    bi-box-arrow-right
                "
            ></i>


            <span
                class="
                    d-none
                    d-sm-inline
                "
            >

                Logout

            </span>

        </a>

    </div>


</nav>


<!-- =======================================================
     SIDEBAR JAVASCRIPT
======================================================= -->

<script>

function toggleSidebar() {

    const sidebar =
        document.querySelector(
            '.sidebar'
        );


    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );


    if (
        !sidebar ||
        !overlay
    ) {

        return;

    }


    sidebar.classList.toggle(
        'open'
    );


    overlay.classList.toggle(
        'show'
    );

}


/* =========================================================
   CLOSE SIDEBAR AFTER MOBILE LINK CLICK
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {


        document
            .querySelectorAll(
                '.sidebar a'
            )
            .forEach(
                function (link) {


                    link.addEventListener(
                        'click',
                        function () {


                            if (
                                window.innerWidth <= 768
                            ) {


                                const sidebar =
                                    document.querySelector(
                                        '.sidebar'
                                    );


                                const overlay =
                                    document.getElementById(
                                        'sidebarOverlay'
                                    );


                                if (
                                    sidebar
                                ) {

                                    sidebar.classList.remove(
                                        'open'
                                    );

                                }


                                if (
                                    overlay
                                ) {

                                    overlay.classList.remove(
                                        'show'
                                    );

                                }

                            }

                        }
                    );

                }
            );

    }
);


/* =========================================================
   PREVENT BROKEN IMAGE DISPLAY
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const logo =
            document.querySelector(
                '.nleta-header-logo'
            );


        if (!logo) {

            return;

        }


        logo.addEventListener(
            'error',
            function () {

                /*
                 * Fallback to the previous logo
                 * if nleta-logo.jpg is not available.
                 */

                if (
                    !this.dataset.fallback
                ) {

                    this.dataset.fallback = '1';

                    this.src =
                        '<?= $_base ?>assets/images/nleta-logo.jpeg';

                }

            }
        );

    }
);

</script>


<!-- =======================================================
     BOOTSTRAP JS
======================================================= -->

<script
    src="<?= $_base ?>assets/js/bootstrap.bundle.min.js"
></script>


</body>

</html>