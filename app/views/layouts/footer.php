    </div> <!-- /.wrapper -->

    <!-- REQUIRED ADMINLTE JS DEPENDENCIES -->
    <script src="<?= BASE_URL ?>/assets/adminlte/plugins/jquery/jquery.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- ADMINLTE CORE JS -->
    <script src="<?= BASE_URL ?>/assets/adminlte/dist/js/adminlte.min.js"></script>

    <!-- GLOBAL JS -->
    <script src="<?= BASE_URL ?>/assets/js/clinical-api.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/clinical-shell.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/app.js"></script>

    <!-- PAGE-SPECIFIC JS (optional) -->
    <?php if (isset($page_js)) echo $page_js; ?>

</body>
</html>